<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer groups (spec §26.3). Exactly one is the default ("Standard",
 * seeded at provisioning); a null customer_group_id means the default.
 */
final readonly class CustomerGroupService
{
    /**
     * @return Collection<int, CustomerGroup>
     */
    public function listGroups(): Collection
    {
        return CustomerGroup::query()->withCount('customers')->orderByDesc('is_default')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createGroup(array $data): CustomerGroup
    {
        /** @var CustomerGroup $group */
        $group = CustomerGroup::query()->create($this->validate($data, null));

        return $group;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(CustomerGroup $group, array $data): CustomerGroup
    {
        $group->fill($this->validate($data, $group))->save();

        return $group;
    }

    /**
     * Members move to the default group (their group becomes null).
     */
    public function deleteGroup(CustomerGroup $group): void
    {
        if ($group->is_default) {
            throw ApiException::unprocessable('default_group_protected', 'The default customer group cannot be deleted.');
        }

        DB::connection('tenant')->transaction(static function () use ($group): void {
            Customer::withTrashed()->where('customer_group_id', $group->id)->update(['customer_group_id' => null]);
            $group->delete();
        });
    }

    /**
     * Assigning the default group stores null, so "default" stays a single
     * definition.
     */
    public function assignCustomerToGroup(Customer $customer, CustomerGroup $group): Customer
    {
        $customer->forceFill(['customer_group_id' => $group->is_default ? null : $group->id])->save();

        return $customer;
    }

    public function defaultGroup(): ?CustomerGroup
    {
        return CustomerGroup::query()->where('is_default', true)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?CustomerGroup $existing): array
    {
        return validator($data, [
            'name' => [$existing === null ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('tenant.customer_groups', 'name')->ignore($existing?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();
    }
}
