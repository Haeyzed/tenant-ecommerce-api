<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Services\DataExportService;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\BulkOperation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Customers and their addresses (spec §26.1, §26.2, §26.4).
 */
final readonly class CustomerService
{
    public const string EXPORT_TYPE = 'customer_personal_data';

    /** The custom-field entity type (§23.1). */
    public const string ENTITY = 'customer';

    public function __construct(
        private CustomerPrivacyRegistry $privacy,
        private DataExportService $exports,
        private CustomFieldService $customFields,
    ) {}

    /**
     * @param  array{search?: string, customer_group_id?: int, is_active?: bool, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function listCustomers(array $filters): LengthAwarePaginator
    {
        return $this->customersQuery($filters)->with(['group:id,name', 'socialAccounts:id,customer_id,provider'])->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * The admin customer list's filters, shared by the list and the
     * customers export (D-134). Anonymised customers are left out.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    public function customersQuery(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes((string) $filters['search'], '%_\\').'%' : null;

        return Customer::query()
            ->when($search !== null, static fn ($q) => $q->where(static fn ($q) => $q
                ->where('name', 'like', $search)->orWhere('email', 'like', $search)->orWhere('phone', 'like', $search)))
            ->when(array_key_exists('customer_group_id', $filters), static fn ($q) => $q->where('customer_group_id', $filters['customer_group_id']))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when(($filters['exclude_anonymized'] ?? false) === true, static fn ($q) => $q->whereNull('anonymized_at'));
    }

    /**
     * Bulk actions (§70.12, D-133): activate, deactivate, assign_group
     * (with customer_group_id). Each customer goes through the single-item
     * method; anonymised customers fail individually.
     *
     * @param  list<int>  $ids
     * @return array{operation_id: string, succeeded: int, failed: int, results: list<array{id: int, status: string, error: string|null, message: string|null}>}
     */
    public function bulk(string $action, array $ids, ?int $groupId, Model $by): array
    {
        $group = $action === 'assign_group' ? CustomerGroup::query()->findOrFail((int) $groupId) : null;

        return BulkOperation::run('customers', $action, $ids, function (int $id) use ($action, $group, $by): void {
            $customer = Customer::query()->findOrFail($id);

            match ($action) {
                'deactivate' => $this->deactivateCustomer($customer, $by),
                'activate' => $this->activateCustomer($customer, $by),
                'assign_group' => app(CustomerGroupService::class)->assignCustomerToGroup($customer, $group),
            };
        });
    }

    public function getCustomer(Customer $customer): Customer
    {
        return $customer->load(['group:id,name', 'addresses', 'socialAccounts:id,customer_id,provider']);
    }

    /**
     * Self-registration (§10.3): email and password are required.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerCustomer(array $data): Customer
    {
        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));

        [$validated, $custom] = $this->validateWithCustomFields($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('tenant.customers', 'email')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], CustomFieldService::PUBLIC, true);

        return $this->persistNew([...$validated, 'name' => trim($validated['name'])], $custom);
    }

    /**
     * A shopper signing up with Google or Facebook (D-132): no password,
     * the store's public custom fields validated as at registration, and
     * the email verified only when the provider vouched for it.
     *
     * @param  array<string, mixed>  $data  name, email, custom_fields?
     */
    public function registerFromSocial(array $data, bool $emailVerified): Customer
    {
        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));
        $data['name'] = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120) ?: 'Customer';

        [$validated, $custom] = $this->validateWithCustomFields($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('tenant.customers', 'email')],
        ], CustomFieldService::PUBLIC, true);

        $customer = $this->persistNew($validated, $custom);

        if ($emailVerified) {
            $customer->markEmailAsVerified();
        }

        return $customer;
    }

    /**
     * Staff-created customer; email and password optional (for example a
     * POS walk-in record without login).
     *
     * @param  array<string, mixed>  $data
     */
    public function createCustomer(array $data, ?Model $by = null): Customer
    {
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim((string) $data['email']));
        }

        [$validated, $custom] = $this->validateWithCustomFields($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255', Rule::unique('tenant.customers', 'email')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'password' => ['sometimes', 'nullable', 'string', Password::defaults()],
            'customer_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.customer_groups', 'id')],
        ], CustomFieldService::ADMIN, true);

        $customer = $this->persistNew([...$validated, 'name' => trim($validated['name'])], $custom);

        ActivityRecorder::tenant('customers', 'Customer created', $customer, [], $by);

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCustomer(Customer $customer, array $data, ?Model $by = null): Customer
    {
        $this->assertNotAnonymised($customer);

        if (isset($data['email'])) {
            $data['email'] = strtolower(trim((string) $data['email']));
        }

        [$validated, $custom] = $this->validateWithCustomFields($data, [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255', Rule::unique('tenant.customers', 'email')->ignore($customer->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ], $by instanceof Customer || $by === null ? CustomFieldService::PUBLIC : CustomFieldService::ADMIN, false);

        // A changed address must be verified again.
        if (array_key_exists('email', $validated) && $validated['email'] !== $customer->email) {
            $customer->email_verified_at = null;
        }

        DB::connection('tenant')->transaction(function () use ($customer, $validated, $custom): void {
            $customer->fill($validated)->save();
            $this->customFields->save($customer, self::ENTITY, $custom);
        });

        if ($by !== null) {
            ActivityRecorder::tenant('customers', 'Customer updated', $customer, ['fields' => array_keys($validated)], $by);
        }

        return $customer;
    }

    /**
     * Undoes a deactivation (D-133). An anonymised customer stays erased.
     */
    public function activateCustomer(Customer $customer, Model $by): Customer
    {
        $this->assertNotAnonymised($customer);
        $customer->forceFill(['is_active' => true])->save();
        ActivityRecorder::tenant('customers', 'Customer activated', $customer, [], $by);

        return $customer;
    }

    public function deactivateCustomer(Customer $customer, Model $by): Customer
    {
        $customer->forceFill(['is_active' => false])->save();
        $customer->tokens()->delete();
        ActivityRecorder::tenant('customers', 'Customer deactivated', $customer, [], $by);

        return $customer;
    }

    /**
     * Account deletion or an erasure request (§26.4): anonymised in one
     * transaction; financial records are kept. Irreversible.
     */
    public function deleteCustomer(Customer $customer, ?Model $by = null): void
    {
        $this->assertNotAnonymised($customer);

        DB::connection('tenant')->transaction(function () use ($customer): void {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $locked->tokens()->delete();
            $this->privacy->erase($locked);

            $locked->forceFill([
                'name' => 'Deleted customer',
                'email' => 'deleted+'.$locked->id.'@invalid',
                'phone' => null,
                'password' => null,
                'email_verified_at' => null,
                'is_active' => false,
                'anonymized_at' => now(),
            ])->save();

            $locked->delete();
        });

        ActivityRecorder::tenant('customers', 'Customer anonymised', $customer, [], $by);
    }

    /**
     * Requests the customer's personal-data export (JSON). The file is
     * always for the customer: the link goes to the customer's email, also
     * when staff request it on their behalf.
     */
    public function exportPersonalData(Customer $customer, ?Model $by = null): DataExport
    {
        $this->assertNotAnonymised($customer);

        if ($customer->email === null) {
            throw ApiException::unprocessable('customer_email_missing', 'The customer has no email address to send the export to.');
        }

        $export = $this->exports->request(self::EXPORT_TYPE, ['customer_id' => $customer->id], 'json', $customer);

        if ($by !== null && ! $by->is($customer)) {
            ActivityRecorder::tenant('customers', 'Personal data export requested for customer', $customer, ['export_id' => $export->id], $by);
        }

        return $export;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addAddress(Customer $customer, array $data): Address
    {
        $this->assertNotAnonymised($customer);
        $validated = $this->validateAddress($data, true);

        return DB::connection('tenant')->transaction(function () use ($customer, $validated): Address {
            $address = new Address($validated);
            $address->customer_id = $customer->id;
            // The first address becomes the default.
            $address->is_default = ! $customer->addresses()->exists() || (bool) ($validated['is_default'] ?? false);
            $address->save();

            if ($address->is_default) {
                $this->clearOtherDefaults($address);
            }

            return $address;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAddress(Address $address, array $data): Address
    {
        $address->fill($this->validateAddress($data, false))->save();

        return $address;
    }

    /**
     * Deleting the default promotes the most recent remaining address.
     */
    public function deleteAddress(Address $address): void
    {
        DB::connection('tenant')->transaction(static function () use ($address): void {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                Address::query()->where('customer_id', $address->customer_id)->orderByDesc('id')->limit(1)->update(['is_default' => true]);
            }
        });
    }

    public function setDefaultAddress(Customer $customer, Address $address): Address
    {
        if ($address->customer_id !== $customer->id) {
            throw ApiException::forbidden('forbidden', 'This address does not belong to the customer.');
        }

        DB::connection('tenant')->transaction(function () use ($address): void {
            $address->forceFill(['is_default' => true])->save();
            $this->clearOtherDefaults($address);
        });

        return $address;
    }

    /**
     * Standard and custom-field rules in one pass, so a single 422 lists
     * both kinds of errors (§23.5).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<mixed>>  $rules
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validateWithCustomFields(array $data, array $rules, string $context, bool $creating): array
    {
        $validator = validator($data, [...$rules, 'custom_fields' => ['sometimes', 'array']]);
        $errors = $validator->errors()->toArray();
        $custom = [];

        try {
            $custom = $this->customFields->validate(self::ENTITY, (array) ($data['custom_fields'] ?? []), $context, $creating);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->errors());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated = $validator->validated();
        unset($validated['custom_fields']);

        return [$validated, $custom];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $custom
     */
    private function persistNew(array $attributes, array $custom): Customer
    {
        return DB::connection('tenant')->transaction(function () use ($attributes, $custom): Customer {
            /** @var Customer $customer */
            $customer = Customer::query()->create([...$attributes, 'is_active' => true]);
            $this->customFields->save($customer, self::ENTITY, $custom);

            return $customer;
        });
    }

    private function clearOtherDefaults(Address $address): void
    {
        Address::query()->where('customer_id', $address->customer_id)->whereKeyNot($address->id)->where('is_default', true)->update(['is_default' => false]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateAddress(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return validator($data, [
            'label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'recipient_name' => [$req, 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address_line_1' => [$req, 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_id' => [$req, 'integer', Rule::exists('landlord.countries', 'id')],
            'state_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.states', 'id')->where('country_id', $data['country_id'] ?? null)],
            'city_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.cities', 'id')->where('state_id', $data['state_id'] ?? null)],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_default' => ['sometimes', 'boolean'],
        ])->validate();
    }

    private function assertNotAnonymised(Customer $customer): void
    {
        if ($customer->anonymized_at !== null) {
            throw ApiException::unprocessable('customer_anonymised', 'This customer was deleted and cannot be changed.');
        }
    }
}
