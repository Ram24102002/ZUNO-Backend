<?php

namespace Zuno\Storefront\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Contracts\Customer;
use Webkul\Customer\Repositories\CustomerAddressRepository;

/**
 * A customer's saved delivery addresses, in the same flat shape the React checkout form uses:
 * { id, name, phone, address, city, state, postcode, isDefault }.
 */
class AddressBook
{
    public const RULES = [
        'name' => 'required|string|max:80',
        'phone' => ['required', 'string', 'regex:/^\+?[\d\s-]{8,20}$/'],
        'address' => 'required|string|max:400',
        'city' => 'required|string|max:80',
        'state' => 'required|string|exists:country_states,code,country_code,IN',
        'postcode' => ['required', 'regex:/^\d{6}$/'],
    ];

    public const MESSAGES = [
        'state.exists' => 'Choose your state.',
        'postcode.regex' => 'Enter a 6-digit PIN code.',
        'phone.regex' => 'A valid phone number is required.',
    ];

    public function __construct(protected CustomerAddressRepository $customerAddressRepository) {}

    public function all(Customer $customer): array
    {
        return $customer->addresses()
            ->orderByDesc('default_address')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($address) => $this->toArray($address))
            ->all();
    }

    /** Saves a new address; the first one, or one marked default, becomes the default. */
    public function create(Customer $customer, array $data, bool $makeDefault = false)
    {
        return DB::transaction(function () use ($customer, $data, $makeDefault) {
            $makeDefault = $makeDefault || ! $customer->addresses()->exists();

            if ($makeDefault) {
                $customer->addresses()->update(['default_address' => 0]);
            }

            return $this->customerAddressRepository->create(array_merge($this->columns($data), [
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'default_address' => $makeDefault,
            ]));
        });
    }

    public function update(Customer $customer, int $id, array $data, bool $makeDefault = false)
    {
        $address = $this->find($customer, $id);

        return DB::transaction(function () use ($customer, $address, $data, $makeDefault) {
            if ($makeDefault) {
                $customer->addresses()->update(['default_address' => 0]);
            }

            return $this->customerAddressRepository->update(
                array_merge($this->columns($data), $makeDefault ? ['default_address' => 1] : []),
                $address->id
            );
        });
    }

    public function delete(Customer $customer, int $id): void
    {
        $address = $this->find($customer, $id);
        $wasDefault = $address->default_address;

        $this->customerAddressRepository->delete($address->id);

        // Someone with addresses always has a default one
        if ($wasDefault && ($next = $customer->addresses()->latest('id')->first())) {
            $next->update(['default_address' => 1]);
        }
    }

    /** Whether this customer already has this address saved (so checkout does not add duplicates). */
    public function has(Customer $customer, array $data): bool
    {
        $columns = $this->columns($data);

        return $customer->addresses()
            ->where('address', $columns['address'])
            ->where('postcode', $columns['postcode'])
            ->where('phone', $columns['phone'])
            ->exists();
    }

    public function toArray($address): array
    {
        return [
            'id' => $address->id,
            'name' => trim($address->first_name.' '.$address->last_name),
            'phone' => $address->phone,
            'address' => $address->address,
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->postcode,
            'isDefault' => (bool) $address->default_address,
        ];
    }

    protected function find(Customer $customer, int $id)
    {
        return $customer->addresses()->findOrFail($id);
    }

    protected function columns(array $data): array
    {
        $name = preg_split('/\s+/', trim($data['name']), 2);

        return [
            'first_name' => $name[0],
            'last_name' => $name[1] ?? '',
            'phone' => trim($data['phone']),
            'address' => implode(PHP_EOL, array_filter(array_map('trim', preg_split('/\R/', $data['address'])))),
            'city' => trim($data['city']),
            'state' => $data['state'],
            'country' => 'IN',
            'postcode' => $data['postcode'],
        ];
    }
}
