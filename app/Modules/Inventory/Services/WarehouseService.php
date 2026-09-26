<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\WarehouseUsage;
use App\Modules\Users\Models\User;
use App\Modules\Users\Support\StaffAccessScope;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Warehouses (spec §32.9). max_warehouses counts active warehouses and is
 * enforced on the create and activate routes by usage.limit (under the
 * per-tenant limit lock).
 */
final readonly class WarehouseService
{
    public function __construct(
        private WarehouseUsage $usage,
        private StaffAccessScope $scope,
    ) {}

    /**
     * @param  array{search?: string, is_active?: bool}  $filters
     * @return Collection<int, Warehouse>
     */
    public function listWarehouses(array $filters = [], ?User $viewer = null): Collection
    {
        return $this->scoped(Warehouse::query(), $viewer)
            ->when(filled($filters['search'] ?? null), static fn (Builder $q) => $q->where(static fn (Builder $w) => $w
                ->where('name', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%')
                ->orWhere('code', (string) $filters['search'])))
            ->when(array_key_exists('is_active', $filters), static fn (Builder $q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('id')
            ->get();
    }

    /**
     * A staff user narrowed to their warehouses sees only those (§25.3).
     *
     * @param  Builder<Warehouse>  $query
     * @return Builder<Warehouse>
     */
    public function scoped(Builder $query, ?User $viewer): Builder
    {
        return $viewer === null ? $query : $this->scope->apply($query, $viewer, null,
            static fn (Builder $q, array $ids) => $q->whereIn('warehouses.id', $ids));
    }

    /**
     * The warehouse ids a viewer may see, or null for all.
     *
     * @return list<int>|null
     */
    public function visibleIds(?User $viewer): ?array
    {
        return $viewer === null || $this->scope->mode($viewer) !== StaffAccessScope::WAREHOUSE ? null : $this->scope->warehouseIds($viewer);
    }

    public function assertVisible(Warehouse $warehouse, ?User $viewer): void
    {
        $ids = $this->visibleIds($viewer);

        if ($ids !== null && ! in_array($warehouse->id, $ids, true)) {
            throw (new ModelNotFoundException)->setModel(Warehouse::class, [$warehouse->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createWarehouse(array $data): Warehouse
    {
        $warehouse = new Warehouse($this->validate($data, null));
        $warehouse->is_active = true;
        $warehouse->save();

        return $warehouse;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateWarehouse(Warehouse $warehouse, array $data): Warehouse
    {
        $warehouse->fill($this->validate($data, $warehouse))->save();

        return $warehouse;
    }

    /**
     * Blocked while stock is reserved there (unshipped orders), and for
     * the last active warehouse (checkout needs one).
     */
    public function deactivateWarehouse(Warehouse $warehouse): Warehouse
    {
        DB::connection('tenant')->transaction(function () use ($warehouse): void {
            $locked = Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                return;
            }

            if (Inventory::query()->where('warehouse_id', $locked->id)->where('reserved_quantity', '>', 0)->exists()) {
                throw ApiException::unprocessable('warehouse_has_reservations', 'Stock is reserved at this warehouse. Ship or move those orders first.');
            }

            if (! Warehouse::query()->where('is_active', true)->whereKeyNot($locked->id)->lockForUpdate()->exists()) {
                throw ApiException::unprocessable('last_active_warehouse', 'At least one warehouse must stay active.');
            }

            $locked->forceFill(['is_active' => false])->save();
        });

        return $warehouse->refresh();
    }

    public function activateWarehouse(Warehouse $warehouse): Warehouse
    {
        $warehouse->forceFill(['is_active' => true])->save();

        return $warehouse;
    }

    /**
     * Allowed only for a warehouse that never held stock and that nothing
     * references; deactivate it otherwise.
     */
    public function deleteWarehouse(Warehouse $warehouse): void
    {
        $usedBy = $this->usage->usedBy($warehouse);

        if ($usedBy !== []) {
            throw ApiException::unprocessable('warehouse_in_use', 'This warehouse has stock history or is referenced. Deactivate it instead.', ['used_by' => $usedBy]);
        }

        if ($warehouse->is_active && ! Warehouse::query()->where('is_active', true)->whereKeyNot($warehouse->id)->exists()) {
            throw ApiException::unprocessable('last_active_warehouse', 'At least one warehouse must stay active.');
        }

        DB::connection('tenant')->transaction(static function () use ($warehouse): void {
            Inventory::query()->where('warehouse_id', $warehouse->id)->delete();
            $warehouse->delete();
        });
    }

    /**
     * The default warehouse, seeded once at provisioning (§9.5): only when
     * the store has none.
     */
    public function ensureDefault(): void
    {
        if (! Warehouse::query()->exists()) {
            $warehouse = new Warehouse(['name' => 'Main', 'code' => Warehouse::DEFAULT_CODE]);
            $warehouse->is_active = true;
            $warehouse->save();
        }
    }

    /**
     * @param  list<int>  $warehouseIds
     */
    public function syncUsers(User $user, array $warehouseIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $warehouseIds)));

        if (Warehouse::query()->whereKey($ids)->count() !== count($ids)) {
            throw ApiException::unprocessable('warehouse_unknown', 'Every warehouse must exist.');
        }

        DB::connection('tenant')->transaction(static function () use ($user, $ids): void {
            DB::connection('tenant')->table('warehouse_user')->where('user_id', $user->id)->delete();
            DB::connection('tenant')->table('warehouse_user')->insert(array_map(static fn (int $id): array => ['warehouse_id' => $id, 'user_id' => $user->id], $ids));
        });
    }

    /**
     * @return list<int>
     */
    public function userWarehouseIds(User $user): array
    {
        return DB::connection('tenant')->table('warehouse_user')->where('user_id', $user->id)->orderBy('warehouse_id')
            ->pluck('warehouse_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?Warehouse $existing): array
    {
        if (isset($data['code']) && is_string($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        return validator($data, [
            'name' => [$existing === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('tenant.warehouses', 'code')->ignore($existing?->id)],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.countries', 'id')],
            'state_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.states', 'id')->where('country_id', $data['country_id'] ?? $existing?->country_id)],
            'city_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.cities', 'id')->where('state_id', $data['state_id'] ?? $existing?->state_id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ])->validate();
    }
}
