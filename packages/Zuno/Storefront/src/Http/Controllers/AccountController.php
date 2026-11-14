<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Zuno\Storefront\Notifications\ResetPassword;
use Zuno\Storefront\Support\AddressBook;

/**
 * Customer accounts for the React site, stored as Bagisto customers (Customers in the admin).
 * Logged-in state is Bagisto's own "customer" session, so orders placed while logged in
 * belong to the account. Guest checkout keeps working.
 */
class AccountController extends Controller
{
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected CustomerGroupRepository $customerGroupRepository,
        protected OrderRepository $orderRepository,
        protected AddressBook $addressBook,
    ) {}

    /** The logged-in customer with their saved addresses, or null. */
    public function show(): JsonResponse
    {
        return response()->json(['customer' => $this->customerData()]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstName' => 'required|string|max:60',
            'lastName' => 'nullable|string|max:60',
            'email' => 'required|email|max:120|unique:customers,email',
            'phone' => ['nullable', 'string', 'regex:/^\+?[\d\s-]{8,20}$/'],
            'password' => ['required', 'string', 'max:100', PasswordRule::min(8)],
        ], [
            'email.unique' => 'An account with this email already exists. Log in instead.',
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        $needsVerification = (bool) core()->getConfigData('customer.settings.email.verification');
        $groupCode = core()->getConfigData('customer.settings.create_new_account_options.default_group') ?: 'general';

        Event::dispatch('customer.registration.before');

        $customer = $this->customerRepository->create([
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'] ?? '',
            'email' => Str::lower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => bcrypt($data['password']),
            'api_token' => Str::random(80),
            'is_verified' => ! $needsVerification,
            'status' => 1,
            'customer_group_id' => $this->customerGroupRepository->findOneWhere(['code' => $groupCode])?->id,
            'channel_id' => core()->getCurrentChannel()->id,
            'token' => Str::random(64),
            'subscribed_to_news_letter' => false,
        ]);

        Event::dispatch('customer.create.after', $customer);
        Event::dispatch('customer.registration.after', $customer);

        if ($needsVerification) {
            return response()->json([
                'customer' => null,
                'message' => 'Account created. Check your email to verify it, then log in.',
            ], 201);
        }

        $this->startSession($request, $customer);

        return response()->json(['customer' => $this->customerData()], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $guard = auth()->guard('customer');

        if (! $guard->attempt($credentials + ['channel_id' => core()->getCurrentChannel()->id])) {
            return response()->json(['error' => 'Wrong email or password.'], 422);
        }

        $customer = $guard->user();

        if (! $customer->status || ! $customer->is_verified) {
            $guard->logout();

            return response()->json([
                'error' => $customer->status ? 'Verify your email address first (check your inbox).' : 'This account has been disabled.',
            ], 403);
        }

        $this->startSession($request, $customer);

        return response()->json(['customer' => $this->customerData()]);
    }

    public function logout(Request $request): JsonResponse
    {
        auth()->guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['customer' => null]);
    }

    /** Always answers the same way, so it cannot be used to find out who has an account. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        Password::broker('customers')->sendResetLink(
            ['email' => Str::lower($data['email'])],
            fn ($customer, $token) => $customer->notify(new ResetPassword($token))
        );

        return response()->json(['message' => 'If an account uses that email, a reset link is on its way.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', 'max:100', PasswordRule::min(8)],
        ]);

        $status = Password::broker('customers')->reset(
            $data + ['password_confirmation' => $data['password']],
            function ($customer, $password) {
                $customer->forceFill(['password' => bcrypt($password), 'remember_token' => Str::random(60)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['error' => 'This reset link is invalid or has expired. Ask for a new one.'], 422);
        }

        return response()->json(['message' => 'Password changed. You can log in now.']);
    }

    public function update(Request $request): JsonResponse
    {
        $customer = $this->customer();

        $data = $request->validate([
            'firstName' => 'required|string|max:60',
            'lastName' => 'nullable|string|max:60',
            'phone' => ['nullable', 'string', 'regex:/^\+?[\d\s-]{8,20}$/'],
            'currentPassword' => [$customer->password ? 'required_with:newPassword' : 'nullable', 'nullable', 'string'],
            'newPassword' => ['nullable', 'string', 'max:100', PasswordRule::min(8)],
        ], ['phone.regex' => 'Enter a valid phone number.']);

        // Accounts made with Google have no password yet, so there is no current one to check
        if (! empty($data['newPassword']) && $customer->password && ! Hash::check((string) $data['currentPassword'], $customer->password)) {
            return response()->json(['error' => 'Your current password is not right.', 'errors' => ['currentPassword' => ['Your current password is not right.']]], 422);
        }

        $this->customerRepository->update(array_filter([
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'] ?? '',
            'phone' => $data['phone'] ?? null,
            'password' => empty($data['newPassword']) ? null : bcrypt($data['newPassword']),
        ], fn ($value) => $value !== null), $customer->id);

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

    /** Cancels an order that has not been paid, invoiced or shipped yet. */
    public function cancel(string $number): JsonResponse
    {
        $order = $this->findOrder($number);

        if (! $order->canCancel() || ! $this->orderRepository->cancel($order)) {
            return response()->json(['error' => 'This order can no longer be cancelled. Message us on Instagram for help.'], 422);
        }

        return response()->json($this->orderData($order->refresh()));
    }

    protected function startSession(Request $request, $customer): void
    {
        auth()->guard('customer')->login($customer);
        $request->session()->regenerate();

        // Lets Bagisto merge any guest cart into the customer's
        Event::dispatch('customer.after.login', $customer);
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
            // False for accounts made with Google; they can set a password without knowing an old one
            'hasPassword' => ! empty($customer->password),
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
