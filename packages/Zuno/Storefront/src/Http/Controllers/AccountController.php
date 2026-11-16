<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Zuno\Storefront\Support\AddressBook;

/**
 * Customer accounts for the React site, stored as Bagisto customers (Customers in the admin).
 * The only way in is "Continue with Google" (GoogleLoginController); there are no passwords.
 * Logged-in state is Bagisto's own "customer" session, so orders placed while logged in
 * belong to the account. Guest checkout keeps working.
 */
class AccountController extends Controller
{
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected OrderRepository $orderRepository,
        protected AddressBook $addressBook,
    ) {}

    /** The logged-in customer with their saved addresses, or null. */
    public function show(): JsonResponse
    {
        return response()->json(['customer' => $this->customerData()]);
    }

    public function logout(Request $request): JsonResponse
    {
        auth()->guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['customer' => null]);
    }

    public function update(Request $request): JsonResponse
    {
        $customer = $this->customer();

        $data = $request->validate([
            'firstName' => 'required|string|max:60',
            'lastName' => 'nullable|string|max:60',
            'phone' => ['nullable', 'string', 'regex:/^\+?[\d\s-]{8,20}$/'],
        ], ['phone.regex' => 'Enter a valid phone number.']);

        $this->customerRepository->update([
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'] ?? '',
            'phone' => $data['phone'] ?? null,
        ], $customer->id);

        return response()->json(['customer' => $this->customerData()]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $data = $request->validate(AddressBook::RULES + ['isDefault' => 'nullable|boolean'], AddressBook::MESSAGES);

        $this->addressBook->create($this->customer(), $data, (bool) ($data['isDefault'] ?? false));

        return response()->json(['customer' => $this->customerData()], 201);
    }

    public function updateAddress(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(AddressBook::RULES + ['isDefault' => 'nullable|boolean'], AddressBook::MESSAGES);

        $this->addressBook->update($this->customer(), $id, $data, (bool) ($data['isDefault'] ?? false));

        return response()->json(['customer' => $this->customerData()]);
    }

    public function destroyAddress(int $id): JsonResponse
    {
        $this->addressBook->delete($this->customer(), $id);

        return response()->json(['customer' => $this->customerData()]);
    }

    public function orders(): JsonResponse
    {
        $orders = $this->customer()->orders()->with('items')->latest('id')->paginate(10);

        return response()->json([
            'orders' => $orders->getCollection()->map(fn ($order) => [
                'number' => (string) $order->increment_id,
                'date' => $order->created_at->toIso8601String(),
                'status' => $order->status,
                'statusLabel' => $this->statusLabel($order->status),
                'total' => $this->money($order->base_grand_total),
                'itemCount' => (int) $order->items->whereNull('parent_id')->sum('qty_ordered'),
            ]),
            'page' => $orders->currentPage(),
            'lastPage' => $orders->lastPage(),
        ]);
    }

    public function order(string $number): JsonResponse
    {
        return response()->json($this->orderData($this->findOrder($number)));
    }

    /**
     * Order tracking without logging in (guest checkout): the order number plus the email or phone used
     * at checkout. The street address and phone are left out of the answer, and cancelling needs an account.
     */
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => 'required|string|max:30',
            'contact' => 'required|string|max:120',
        ]);

        $order = $this->orderRepository->getModel()
            ->where('increment_id', ltrim(trim($data['number']), '#'))
            ->first();

        $contact = trim($data['contact']);
        $digits = substr(preg_replace('/\D/', '', $contact), -10);
        $address = $order?->shipping_address ?? $order?->billing_address;

        $matches = $order && (
            (str_contains($contact, '@') && strcasecmp($order->customer_email, $contact) === 0)
            || (strlen($digits) === 10 && $address && substr(preg_replace('/\D/', '', (string) $address->phone), -10) === $digits)
        );

        if (! $matches) {
            return response()->json(['error' => "We couldn't find an order with those details. Check the order number and the email or phone you used at checkout."], 404);
        }

        $result = $this->orderData($order);
        $result['canCancel'] = false;
        if ($result['address']) {
            $result['address'] = ['name' => $result['address']['name'], 'city' => $result['address']['city'], 'state' => $result['address']['state']];
        }

        return response()->json($result);
    }

    /** Cancels an order that has not been paid, invoiced or shipped yet. */
    public function cancel(string $number): JsonResponse
    {
        $order = $this->findOrder($number);

        if (! $order->canCancel() || ! $this->orderRepository->cancel($order)) {
            return response()->json(['error' => 'This order can no longer be cancelled. Message us on Instagram for help.'], 422);
        }

        return response()->json($this->orderData($order->refresh()));
    }

    protected function customer()
    {
        return auth()->guard('customer')->user() ?? abort(401, 'Log in first.');
    }

    protected function customerData(): ?array
    {
        $customer = auth()->guard('customer')->user();

        if (! $customer) {
            return null;
        }

        $customer->refresh();

        return [
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'name' => trim($customer->first_name.' '.$customer->last_name),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'addresses' => $this->addressBook->all($customer),
        ];
    }

    protected function findOrder(string $number)
    {
        return $this->customer()->orders()->where('increment_id', $number)->firstOrFail();
    }

    protected function orderData($order): array
    {
        $address = $order->shipping_address ?? $order->billing_address;

        return [
            'number' => (string) $order->increment_id,
            'date' => $order->created_at->toIso8601String(),
            'status' => $order->status,
            'statusLabel' => $this->statusLabel($order->status),
            'canCancel' => $order->canCancel(),
            'payment' => $order->payment?->method === 'razorpay' ? 'Paid online (Razorpay)' : 'Pay on delivery',
            'items' => $order->items->whereNull('parent_id')->values()->map(fn ($item) => [
                'name' => $item->name,
                'productId' => $item->product?->url_key,
                'color' => $item->additional['attributes']['color']['option_label'] ?? null,
                'size' => $item->additional['attributes']['size']['option_label'] ?? null,
                'quantity' => (int) $item->qty_ordered,
                'price' => $this->money($item->base_price),
                'total' => $this->money($item->base_total),
                'image' => $item->product?->images->sortBy('position')->first()
                    ? parse_url($item->product->images->sortBy('position')->first()->url, PHP_URL_PATH)
                    : null,
                'custom' => ! empty($item->additional['zuno_design']),
                'shipped' => (int) $item->qty_shipped,
            ]),
            'subtotal' => $this->money($order->base_sub_total),
            'discount' => $this->money($order->base_discount_amount),
            'shipping' => $this->money($order->base_shipping_amount),
            'total' => $this->money($order->base_grand_total),
            'coupon' => $order->coupon_code,
            'address' => $address ? [
                'name' => trim($address->first_name.' '.$address->last_name),
                'address' => $address->address,
                'city' => $address->city,
                'state' => $address->state,
                'postcode' => $address->postcode,
                'phone' => $address->phone,
            ] : null,
            'tracking' => $order->shipments->pluck('track_number')->filter()->values(),
        ];
    }

    /** Shopper-friendly wording for Bagisto's order statuses. */
    protected function statusLabel(string $status): string
    {
        return [
            'pending' => 'Placed',
            'pending_payment' => 'Awaiting payment',
            'processing' => 'Processing',
            // Bagisto marks an order completed once it is fully invoiced and shipped
            'completed' => 'Shipped',
            'canceled' => 'Cancelled',
            'closed' => 'Refunded',
            'fraud' => 'On hold',
        ][$status] ?? Str::headline($status);
    }

    protected function money($amount): int|float
    {
        $amount = round((float) $amount, 2);

        return fmod($amount, 1.0) === 0.0 ? (int) $amount : $amount;
    }
}
