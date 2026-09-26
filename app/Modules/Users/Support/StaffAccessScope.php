<?php

declare(strict_types=1);

namespace App\Modules\Users\Support;

use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * tenant_settings.staff_data_access_scope (spec §25.3): narrows which
 * records a staff user sees in list and show queries, on top of
 * permissions. Owners and admins are never narrowed.
 *
 * - own: records the user created (the caller names the column).
 * - warehouse: records tied to the user's assigned warehouses. The
 *   Inventory module registers the assignment resolver when warehouses are
 *   built (§32.3); until then a narrowed user has no warehouses and sees no
 *   warehouse-scoped records (fail closed).
 */
final class StaffAccessScope
{
    public const string ALL = 'all';

    public const string OWN = 'own';

    public const string WAREHOUSE = 'warehouse';

    /** @var (Closure(User): list<int>)|null */
    private ?Closure $warehouses = null;

    public function __construct(private readonly TenantSettingsService $settings) {}

    /**
     * @param  Closure(User): list<int>  $resolver
     */
    public function resolveWarehousesWith(Closure $resolver): void
    {
        $this->warehouses = $resolver;
    }

    public function mode(User $user): string
    {
        if ($user->hasAnyRole(['owner', 'admin'])) {
            return self::ALL;
        }

        return (string) ($this->settings->get('staff_data_access_scope') ?: self::ALL);
    }

    /**
     * @return list<int>
     */
    public function warehouseIds(User $user): array
    {
        return $this->warehouses === null ? [] : ($this->warehouses)($user);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string|null  $ownColumn  the creator column for "own"; null when the record type has none
     * @param  (Closure(Builder<TModel>, list<int>): void)|null  $warehouse  constrains the query to warehouse ids; null when not warehouse-scoped
     * @return Builder<TModel>
     */
    public function apply(Builder $query, User $user, ?string $ownColumn, ?Closure $warehouse = null): Builder
    {
        return match ($this->mode($user)) {
            self::OWN => $ownColumn === null ? $query : $query->where($ownColumn, $user->id),
            self::WAREHOUSE => $warehouse === null ? $query : tap($query, fn (Builder $q) => $warehouse($q, $this->warehouseIds($user))),
            default => $query,
        };
    }
}
