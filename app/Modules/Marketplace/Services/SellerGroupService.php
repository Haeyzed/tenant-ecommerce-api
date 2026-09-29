<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Seller groups (spec §50.1, §50.6). No default group is seeded.
 */
final class SellerGroupService
{
    /**
     * @param  array<string, mixed>  $data  name, description?, default_commission_rate?
     */
    public function createGroup(array $data): SellerGroup
    {
        return SellerGroup::query()->create($this->validate($data, null));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(SellerGroup $group, array $data): SellerGroup
    {
        $group->fill($this->validate($data, $group))->save();

        return $group;
    }

    /**
     * Members fall back to their own rate, else the store default.
     */
    public function deleteGroup(SellerGroup $group): void
    {
        DB::connection('tenant')->transaction(static function () use ($group): void {
            Seller::withTrashed()->where('seller_group_id', $group->id)->update(['seller_group_id' => null]);
            $group->delete();
        });
    }

    /**
     * @return Collection<int, SellerGroup>
     */
    public function listGroups(): Collection
    {
        return SellerGroup::query()->withCount('sellers')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?SellerGroup $group): array
    {
        $required = $group === null ? 'required' : 'sometimes';

        return Validator::make($data, [
            'name' => [$required, 'string', 'max:120', Rule::unique('tenant.seller_groups', 'name')->ignore($group?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_commission_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
        ])->validate();
    }
}
