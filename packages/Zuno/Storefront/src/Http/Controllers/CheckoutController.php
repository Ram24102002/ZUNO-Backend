<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Webkul\Checkout\Facades\Cart;
use Webkul\CartRule\Repositories\CartRuleCouponRepository;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Core\Repositories\CountryStateRepository;
use Webkul\Razorpay\Payment\RazorpayPayment;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderCommentRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\OrderTransactionRepository;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\Shipping\Facades\Shipping;
use Zuno\Storefront\Support\AddressBook;
use Zuno\Storefront\Support\Catalog;
use Zuno\Storefront\Support\Designs;

/**
 * One-shot checkout for the React site. The browser keeps its cart; on "Place order" it sends
 * every line plus the delivery details, and this builds a Bagisto cart from them, prices it with
 * Bagisto's own rules and stock, and turns it into a Bagisto order.
 *
 * Cash on delivery and manual UPI via WhatsApp: the order is created straight away.
 * Razorpay: a Razorpay order is opened for the cart total and the Bagisto order is only created
 * once the payment is verified (POST /orders/pay), the same way Bagisto's own Razorpay checkout works.
 */
class CheckoutController extends Controller
{
    private const PAID_STATUSES = ['captured', 'authorized'];

    private const FREE_SHIPPING = 'free_free';

    public function __construct(
        protected Catalog $catalog,
        protected Designs $designs,
        protected CartRepository $cartRepository,
        protected OrderRepository $orderRepository,
        protected OrderCommentRepository $orderCommentRepository,
        protected OrderTransactionRepository $orderTransactionRepository,
        protected InvoiceRepository $invoiceRepository,
        protected CountryStateRepository $countryStateRepository,
        protected RazorpayPayment $razorpay,
        protected CartRuleCouponRepository $cartRuleCouponRepository,
        protected AddressBook $addressBook,
    ) {}

    /**
     * Prices the browser's cart with Bagisto's rules (and a coupon, if given) without keeping anything:
     * the cart is built inside a transaction that is always rolled back.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge($this->itemRules(), [
            'coupon' => 'nullable|string|max:50',
        ]), ['items.required' => 'Your cart is empty.']);

        $saved = [];
        DB::beginTransaction();

        try {
            Cart::resetCart();

            foreach ($data['items'] as $line) {
                $this->addLine($line, $saved, quoteOnly: true);
            }

            $couponError = null;

            try {
                $this->applyCoupon($data['coupon'] ?? null);
            } catch (CheckoutError $e) {
                $couponError = $e->getMessage();
            }

            Cart::collectTotals();
            $cart = Cart::getCart();

            $quote = $this->totals($cart) + ['coupon' => $cart->coupon_code, 'couponError' => $couponError];
        } catch (CheckoutError $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => $this->friendly($e)], 400);
        } finally {
            DB::rollBack();
            Cart::resetCart();
            session()->forget('cart');
        }

        return response()->json($quote);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateOrder($request);
        $paymentChoice = $data['paymentMethod'] ?? null;
        $method = $paymentChoice === null && $this->razorpay->isAvailable() ? 'razorpay' : 'cashondelivery';
        $savedFiles = [];

        try {
            $cart = DB::transaction(function () use ($data, $method, &$savedFiles) {
                Cart::resetCart();

                foreach ($data['items'] as $line) {
                    $this->addLine($line, $savedFiles);
                }

                $this->applyCoupon($data['coupon'] ?? null);
                $this->saveCheckoutDetails($data, $method);

                // A coupon can stop applying once the address is known (e.g. a rule for one state)
                if (! empty($data['coupon']) && Cart::getCart()->coupon_code !== $data['coupon']) {
                    throw new CheckoutError('This coupon cannot be used with your order.');
                }

                return Cart::getCart();
            });
        } catch (CheckoutError $e) {
            $this->designs->delete($savedFiles);

            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->designs->delete($savedFiles);
            report($e);

            return response()->json(['error' => $this->friendly($e)], 400);
        }

        $customer = auth()->guard('customer')->user();

        if ($customer && ($data['saveAddress'] ?? false) && ! $this->addressBook->has($customer, $data)) {
            $this->addressBook->create($customer, $data);
        }

        if ($method === 'cashondelivery') {
            $paymentChoice ??= 'cod';
            $order = $this->placeOrder($cart, $data['note'] ?? null, $paymentChoice);

            return response()->json($this->orderSummary($order) + ['paymentMethod' => $paymentChoice], 201);
        }

        try {
            $razorpayOrder = $this->razorpay->createOrder($cart);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Could not start the payment. Please try again.'], 502);
        }

        return response()->json($this->totals($cart) + [
            'id' => null,
            // Lets this browser finish the payment for this cart, and nothing else
            'checkout' => Crypt::encryptString(json_encode([
                'cart' => $cart->id,
                'razorpayOrder' => $razorpayOrder['id'],
                'note' => $data['note'] ?? null,
            ])),
            'razorpay' => ['orderId' => $razorpayOrder['id'], 'keyId' => $this->razorpay->getApiKey()],
        ], 201);
    }

    /** Called by the browser after the Razorpay popup succeeds. */
    public function pay(Request $request): JsonResponse
    {
        $request->validate([
            'checkout' => 'required|string',
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        try {
            $checkout = json_decode(Crypt::decryptString($request->input('checkout')), true);
        } catch (DecryptException) {
            return response()->json(['error' => 'The payment could not be verified'], 400);
        }

        $razorpayOrderId = $request->input('razorpay_order_id');
        $paymentId = $request->input('razorpay_payment_id');

        if (
            ($checkout['razorpayOrder'] ?? null) !== $razorpayOrderId
            || ! $this->razorpay->verifySignature($razorpayOrderId, $paymentId, $request->input('razorpay_signature'))
        ) {
            return response()->json(['error' => 'The payment could not be verified'], 400);
        }

        // A retried request for a payment we already booked gets the same order back
        if ($existing = $this->orderRepository->findOneWhere(['cart_id' => $checkout['cart']])) {
            return response()->json($this->orderSummary($existing));
        }

        $cart = $this->cartRepository->find($checkout['cart']);

        if (! $cart || ! $cart->is_active) {
            return response()->json(['error' => 'This checkout has expired. Please place the order again.'], 410);
        }

        Cart::setCart($cart);

        if (! $this->paymentCoversCart($razorpayOrderId, $paymentId, $cart)) {
            return response()->json(['error' => 'The payment could not be verified'], 400);
        }

        $order = DB::transaction(function () use ($cart, $checkout, $razorpayOrderId, $paymentId, $request) {
            $orderData = (new OrderResource($cart))->jsonSerialize();
            $orderData['payment']['additional'] = [
                'status' => Invoice::STATUS_PAID,
                'razorpay_order_id' => $razorpayOrderId,
                'razorpay_payment_id' => $paymentId,
            ];

            $order = $this->orderRepository->create($orderData);
            $this->orderRepository->update(['status' => Order::STATUS_PROCESSING], $order->id);

            $invoice = $this->invoiceRepository->create([
                'order_id' => $order->id,
                'invoice' => ['items' => $order->items->mapWithKeys(fn ($item) => [$item->id => $item->qty_to_invoice])->filter()->all()],
            ]);

            $this->orderTransactionRepository->create([
                'transaction_id' => $paymentId,
                'status' => 'captured',
                'type' => 'razorpay',
                'payment_method' => 'razorpay',
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'amount' => $orderData['base_grand_total'] ?? 0,
                'data' => json_encode([
                    'razorpay_order_id' => $razorpayOrderId,
                    'razorpay_payment_id' => $paymentId,
                    'razorpay_signature' => $request->input('razorpay_signature'),
                ]),
            ]);

            $this->addNote($order, $checkout['note'] ?? null);
            Cart::deActivateCart();

            return $order->refresh();
        });

        return response()->json($this->orderSummary($order));
    }

    protected function validateOrder(Request $request): array
    {
        $guest = ! auth()->guard('customer')->check();

        return $request->validate(array_merge($this->itemRules(), [
            'name' => 'required|string|max:80',
            // Logged-in customers order under their account's email
            'email' => [$guest ? 'required' : 'nullable', 'email', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+?[\d\s-]{8,20}$/'],
            'address' => 'required|string|max:400',
            'city' => 'required|string|max:80',
            'state' => 'required|string|max:10',
            'postcode' => ['required', 'regex:/^\d{6}$/'],
            'note' => 'nullable|string|max:500',
            'coupon' => 'nullable|string|max:50',
            'saveAddress' => 'nullable|boolean',
            'paymentMethod' => ['nullable', 'string', Rule::in(['upi', 'cod'])],
        ]), [
            'postcode.regex' => 'Enter a 6-digit PIN code.',
            'phone.regex' => 'A valid phone number is required.',
            'items.required' => 'Your cart is empty.',
        ]);
    }

    protected function itemRules(): array
    {
        $max = config('zuno');

        return [
            'items' => 'required|array|min:1|max:'.$max['max_lines'],
            'items.*.productId' => 'required|string|max:120',
            'items.*.color' => 'required|string|max:40',
            'items.*.size' => 'required|string|max:5',
            'items.*.quantity' => 'required|integer|min:1|max:'.$max['max_quantity'],
            'items.*.design' => 'nullable|array',
            'items.*.design.preview' => 'nullable|string',
            'items.*.design.artwork' => 'nullable|array|max:'.$max['max_artwork'],
            'items.*.design.artwork.*' => 'string',
        ];
    }

    /** Applies a coupon to the cart being built, or explains why it cannot be used. */
    protected function applyCoupon(?string $code): void
    {
        $code = trim((string) $code);

        if ($code === '') {
            return;
        }

        $coupon = $this->cartRuleCouponRepository->findOneByField('code', $code);

        if (! $coupon || ! $coupon->cart_rule?->status) {
            throw new CheckoutError('That coupon code is not valid.');
        }

        Cart::setCouponCode($coupon->code)->collectTotals();

        if (Cart::getCart()->coupon_code !== $coupon->code) {
            throw new CheckoutError('This coupon cannot be used with your cart.');
        }
    }

    /** Subtotal, discount and total of a cart or order, in rupees. */
    protected function totals($cartOrOrder): array
    {
        return [
            'subtotal' => $this->money($cartOrOrder->base_sub_total),
            'discount' => $this->money($cartOrOrder->base_discount_amount),
            'total' => $this->money($cartOrOrder->base_grand_total),
        ];
    }

    /** Adds one browser cart line to the Bagisto cart. Prices always come from Bagisto. */
    protected function addLine(array $line, array &$savedFiles, bool $quoteOnly = false): void
    {
        $quantity = (int) $line['quantity'];

        if ($line['productId'] === config('zuno.custom_tee.id')) {
            $tee = config('zuno.custom_tee');
            $product = $this->catalog->customTee();

            if (! $product || ! $product->status) {
                throw new CheckoutError('Custom tees are not available right now.');
            }
            if (! in_array($line['color'], $tee['colors'], true) || ! in_array($line['size'], $tee['sizes'], true)) {
                throw new CheckoutError('Choose a colour and size for your custom tee.');
            }

            // A price check does not need the artwork, so nothing is written to disk for it
            $design = $quoteOnly ? [] : $this->designs->store($line['design'] ?? [], $savedFiles);

            Cart::addProduct($product, [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'zuno_design' => $design,
                // Shown by Bagisto next to the item in the admin, invoices and emails
                'attributes' => [
                    'color' => ['attribute_name' => 'Color', 'option_label' => $line['color']],
                    'size' => ['attribute_name' => 'Size', 'option_label' => $line['size']],
                ],
            ]);

            return;
        }

        $product = $this->catalog->findBySlug($line['productId']);

        if (! $product) {
            throw new CheckoutError('An item in your cart is no longer available.');
        }

        $variant = $this->catalog->variant($product, $line['color'], $line['size']);

        if (! $variant || ! $variant->status) {
            throw new CheckoutError("Choose a colour and size for {$product->name}.");
        }
        if (! $variant->haveSufficientQuantity($quantity)) {
            throw new CheckoutError("{$product->name} in {$line['color']}, size {$line['size']} is out of stock.");
        }

        Cart::addProduct($product, [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'selected_configurable_option' => $variant->id,
            'super_attribute' => $product->super_attributes->mapWithKeys(
                fn ($attribute) => [$attribute->id => $variant->{$attribute->code}]
            )->all(),
        ]);
    }

    protected function saveCheckoutDetails(array $data, string $method): void
    {
        $state = $this->countryStateRepository->findOneWhere(['country_code' => 'IN', 'code' => $data['state']]);

        if (! $state) {
            throw new CheckoutError('Choose your state.');
        }

        $name = preg_split('/\s+/', trim($data['name']), 2);

        $address = [
            'first_name' => $name[0],
            'last_name' => $name[1] ?? '',
            'email' => auth()->guard('customer')->user()?->email ?? $data['email'],
            'phone' => $data['phone'],
            'address' => preg_split('/\R/', trim($data['address'])),
            'city' => $data['city'],
            'state' => $state->code,
            'country' => 'IN',
            'postcode' => $data['postcode'],
            'use_for_shipping' => true,
        ];

        Cart::saveAddresses(['billing' => $address]);
        Cart::collectTotals();

        if (Cart::getCart()->haveStockableItems()) {
            Shipping::collectRates();

            if (! Cart::saveShippingMethod(self::FREE_SHIPPING)) {
                throw new CheckoutError('Delivery is not available right now.');
            }
        }

        Cart::savePaymentMethod(['method' => $method]);
        Cart::collectTotals();

        if (Cart::hasError()) {
            throw new CheckoutError(collect(Cart::getErrors())->flatten()->first() ?: 'Your cart could not be checked out.');
        }
    }

    protected function placeOrder($cart, ?string $note, string $paymentChoice)
    {
        return DB::transaction(function () use ($cart, $note, $paymentChoice) {
            $order = $this->orderRepository->create((new OrderResource($cart))->jsonSerialize());
            $this->addNote($order, $note);

            if ($paymentChoice === 'upi') {
                $this->orderCommentRepository->create([
                    'order_id' => $order->id,
                    'comment' => 'Payment requested by UPI. Send the QR code and UPI ID via WhatsApp. Payment is pending.',
                    'customer_notified' => 0,
                ]);
            }

            Cart::deActivateCart();

            return $order->refresh();
        });
    }

    /** Whether Razorpay holds an unused payment for this cart's exact total. */
    protected function paymentCoversCart(string $razorpayOrderId, string $paymentId, $cart): bool
    {
        if ($this->orderTransactionRepository->findWhere(['transaction_id' => $paymentId])->isNotEmpty()) {
            return false;
        }

        $payment = $this->razorpay->fetchPayment($paymentId);

        return $payment
            && $payment['order_id'] === $razorpayOrderId
            && in_array($payment['status'], self::PAID_STATUSES, true)
            && strtoupper((string) $payment['currency']) === 'INR'
            && $payment['amount'] === $this->razorpay->getAmountInSubunits($cart);
    }

    protected function addNote($order, ?string $note): void
    {
        if ($note = trim((string) $note)) {
            $this->orderCommentRepository->create([
                'order_id' => $order->id,
                'comment' => 'Customer note: '.$note,
                'customer_notified' => 0,
            ]);
        }
    }

    protected function orderSummary($order): array
    {
        return $this->totals($order) + [
            'id' => (string) $order->increment_id,
            'paid' => $order->payment?->method === 'razorpay',
            'razorpay' => null,
        ];
    }

    protected function money($amount): int|float
    {
        $amount = round((float) $amount, 2);

        return fmod($amount, 1.0) === 0.0 ? (int) $amount : $amount;
    }

    /** Bagisto's cart errors are meant for shoppers; anything else gets a generic message. */
    protected function friendly(\Throwable $e): string
    {
        return $e instanceof \Webkul\Product\Exceptions\InsufficientProductInventoryException
            ? 'Some items are no longer in stock in the quantity you chose.'
            : 'Your order could not be placed. Please try again.';
    }
}
