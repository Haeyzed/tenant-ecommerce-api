<?php

declare(strict_types=1);

namespace App\Modules\Lookups\Support;

use App\Modules\Access\Services\RoleService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Dashboard\Services\Tenant\TenantDashboardService;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\PromotionTarget;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Shipping\Models\DeliveryAssignment;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Modules\World\Services\WorldService;
use App\Shared\Metrics\DateRange;
use App\Shared\Support\DisplayFormat;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The whitelist of lookup keys per route group (spec §45). Every lookup
 * returns [{value, label, meta?}]; none returns a full resource. Code
 * modules add their keys here when they are built.
 */
final readonly class LookupRegistry
{
    public const string LANDLORD_PUBLIC = 'landlord.public';

    public const string LANDLORD_ADMIN = 'landlord.admin';

    public const string TENANT_PUBLIC = 'tenant.public';

    public const string TENANT_ADMIN = 'tenant.admin';

    public function __construct(
        private WorldService $world,
        private ModuleRegistry $modules,
        private PlatformSettingsService $platformSettings,
    ) {}

    public function has(string $context, string $key): bool
    {
        return array_key_exists($key, $this->map($context));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resolve(string $context, string $key, Request $request): array
    {
        return ($this->map($context)[$key])($request);
    }

    /**
     * @return list<string>
     */
    public function keys(string $context): array
    {
        return array_keys($this->map($context));
    }

    /**
     * @return array<string, Closure(Request): list<array<string, mixed>>>
     */
    private function map(string $context): array
    {
        $world = [
            'countries' => fn (): array => $this->world->countries(),
            'currencies' => fn (): array => $this->world->currencies(),
        ];

        $displayFormats = static fn (): array => [
            ...array_map(static fn (string $f): array => ['value' => $f, 'label' => $f, 'meta' => ['group' => 'date_format']], DisplayFormat::values()),
            ['value' => '24h', 'label' => '24-hour', 'meta' => ['group' => 'time_format']],
            ['value' => '12h', 'label' => '12-hour', 'meta' => ['group' => 'time_format']],
        ];

        // Date range presets and comparison options (§22.1).
        $dashboardRanges = static fn (): array => [
            ...array_map(static fn (string $p): array => ['value' => $p, 'label' => ucfirst(str_replace('_', ' ', $p)), 'meta' => ['group' => 'range', 'default' => $p === 'last_30_days']], DateRange::PRESETS),
            ...array_map(static fn (string $c): array => ['value' => $c, 'label' => ucfirst(str_replace('_', ' ', $c)), 'meta' => ['group' => 'compare', 'default' => $c === 'previous_period']], DateRange::COMPARES),
            ...array_map(static fn (string $i): array => ['value' => $i, 'label' => ucfirst($i), 'meta' => ['group' => 'interval', 'default' => $i === 'auto']], DateRange::INTERVALS),
        ];

        return match ($context) {
            self::LANDLORD_PUBLIC => $world,

            self::LANDLORD_ADMIN => $world + [
                'plans' => static fn (): array => Plan::query()->orderBy('sort_order')->get(['id', 'name', 'slug', 'is_active', 'is_public'])
                    ->map(static fn (Plan $p): array => ['value' => $p->id, 'label' => $p->name, 'meta' => ['slug' => $p->slug, 'is_active' => $p->is_active, 'is_public' => $p->is_public]])
                    ->all(),
                'features' => fn (): array => array_values(array_map(
                    static fn ($d): array => ['value' => $d->key, 'label' => $d->name, 'meta' => ['class' => $d->class, 'section' => $d->section, 'requires' => $d->requires]],
                    $this->modules->all(),
                )),
                'limit-keys' => static fn (): array => array_map(
                    static fn (string $key, array $d): array => ['value' => $key, 'label' => (string) $d['label'], 'meta' => ['kind' => $d['kind'], 'unlimited_allowed' => $d['unlimited_allowed']]],
                    array_keys((array) config('limits')),
                    array_values((array) config('limits')),
                ),
                'tenant-statuses' => static fn (): array => array_map(
                    static fn (TenantStatus $s): array => ['value' => $s->value, 'label' => ucwords(str_replace('_', ' ', $s->value))],
                    TenantStatus::cases(),
                ),
                'database-servers' => static fn (): array => DatabaseServer::query()->where('is_accepting_tenants', true)->orderBy('name')->get()
                    ->map(static fn (DatabaseServer $s): array => ['value' => $s->id, 'label' => $s->name, 'meta' => ['utilisation' => round($s->utilisation() * 100, 1)]])
                    ->all(),
                'payment-gateways' => static fn (): array => array_map(
                    static fn (string $p): array => ['value' => $p, 'label' => ucfirst($p)],
                    array_keys((array) config('payments.providers')),
                ),
                'payment-modes' => static fn (): array => [['value' => 'test', 'label' => 'Test'], ['value' => 'live', 'label' => 'Live']],
                'platform-setting-groups' => fn (): array => array_map(
                    static fn (string $g): array => ['value' => $g, 'label' => ucwords(str_replace('_', ' ', $g))],
                    $this->platformSettings->groups(),
                ),
                'display-formats' => $displayFormats,
                'dashboard-ranges' => $dashboardRanges,
            ],

            self::TENANT_PUBLIC => $world + [
                'states' => fn (Request $r): array => $this->world->states($this->requiredId($r, 'country_id')),
                'cities' => fn (Request $r): array => $this->world->cities($this->requiredId($r, 'state_id')),
                'languages' => fn (): array => $this->world->languages(),
                'timezones' => fn (): array => $this->world->timezones(),
                'categories' => static fn (): array => DB::connection('tenant')->table('categories')->where('is_active', true)
                    ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug', 'parent_id', 'sort_order'])
                    ->map(static fn ($c): array => ['value' => (int) $c->id, 'label' => $c->name, 'meta' => ['parent_id' => $c->parent_id === null ? null : (int) $c->parent_id, 'slug' => $c->slug, 'sort_order' => (int) $c->sort_order]])
                    ->all(),
                'brands' => static fn (): array => DB::connection('tenant')->table('brands')->orderBy('name')->get(['id', 'name', 'slug'])
                    ->map(static fn ($b): array => ['value' => (int) $b->id, 'label' => $b->name, 'meta' => ['slug' => $b->slug]])
                    ->all(),
                'product-types' => static fn (): array => self::enum(Product::TYPES),
                'product-options' => static fn (): array => ProductOption::query()->with(['values' => static fn ($q) => $q->orderBy('sort_order')])
                    ->orderBy('sort_order')->get()
                    ->map(static fn (ProductOption $o): array => ['value' => $o->id, 'label' => $o->name, 'meta' => [
                        'values' => $o->values->map(static fn ($v): array => ['value' => $v->id, 'label' => $v->value])->values()->all(),
                    ]])->all(),
                'order-statuses' => static fn (): array => self::enum(Order::STATUSES),
                'payment-statuses' => static fn (): array => self::enum(Order::PAYMENT_STATUSES),
                'return-statuses' => static fn (): array => self::enum(OrderReturn::STATUSES),
                'return-reasons' => static fn (): array => DB::connection('tenant')->table('return_reasons')->where('is_active', true)->orderBy('label')
                    ->get(['id', 'label', 'requires_photo'])
                    ->map(static fn ($r): array => ['value' => (int) $r->id, 'label' => $r->label, 'meta' => ['requires_photo' => (bool) $r->requires_photo]])
                    ->all(),
            ],

            self::TENANT_ADMIN => [
                'roles' => static fn (): array => Role::query()->where('guard_name', RoleService::GUARD)->orderBy('name')->get(['id', 'name'])
                    ->map(static fn (Role $r): array => ['value' => $r->id, 'label' => $r->name, 'meta' => ['protected' => in_array($r->name, RoleService::PROTECTED_ROLES, true)]])
                    ->all(),
                'permissions' => static function (): array {
                    /** @var Tenant $tenant */
                    $tenant = tenant();
                    $result = [];

                    foreach (app(RoleService::class)->listPermissions($tenant) as $group) {
                        foreach ($group['permissions'] as $permission) {
                            $result[] = ['value' => $permission, 'label' => $permission, 'meta' => ['group' => $group['module'], 'state' => $group['state']]];
                        }
                    }

                    return $result;
                },
                'staff-users' => static fn (): array => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])
                    ->map(static fn (User $u): array => ['value' => $u->id, 'label' => $u->name, 'meta' => ['email' => $u->email]])
                    ->all(),
                'customer-groups' => static fn (): array => DB::connection('tenant')->table('customer_groups')->orderBy('name')->get(['id', 'name', 'is_default'])
                    ->map(static fn ($g): array => ['value' => (int) $g->id, 'label' => $g->name, 'meta' => ['is_default' => (bool) $g->is_default]])
                    ->all(),
                'warehouses' => static fn (): array => DB::connection('tenant')->table('warehouses')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])
                    ->map(static fn ($w): array => ['value' => (int) $w->id, 'label' => $w->name, 'meta' => ['code' => $w->code]])
                    ->all(),
                'units-of-measure' => static fn (): array => DB::connection('tenant')->table('units_of_measure')->orderBy('name')->get(['id', 'name', 'short_code', 'allows_decimal'])
                    ->map(static fn ($u): array => ['value' => (int) $u->id, 'label' => $u->name, 'meta' => ['short_code' => $u->short_code, 'allows_decimal' => (bool) $u->allows_decimal]])
                    ->all(),
                'shipping-zones' => static fn (): array => DB::connection('tenant')->table('shipping_zones')->orderBy('name')->get(['id', 'name', 'is_active'])
                    ->map(static fn ($z): array => ['value' => (int) $z->id, 'label' => $z->name, 'meta' => ['is_active' => (bool) $z->is_active]])
                    ->all(),
                'shipping-methods' => static fn (Request $r): array => DB::connection('tenant')->table('shipping_methods')
                    ->when(is_numeric($r->query('zone_id')), static fn ($q) => $q->where('shipping_zone_id', (int) $r->query('zone_id')))
                    ->orderBy('name')->get(['id', 'name', 'shipping_zone_id', 'fulfillment_type', 'is_active'])
                    ->map(static fn ($m): array => ['value' => (int) $m->id, 'label' => $m->name, 'meta' => [
                        'zone_id' => (int) $m->shipping_zone_id, 'fulfillment_type' => $m->fulfillment_type, 'is_active' => (bool) $m->is_active,
                    ]])->all(),
                'shipping-fulfillment-types' => static fn (): array => self::enum([ShippingMethod::COURIER, ShippingMethod::IN_HOUSE]),
                'delivery-assignment-statuses' => static fn (): array => self::enum([DeliveryAssignment::ASSIGNED, DeliveryAssignment::PICKED_UP, DeliveryAssignment::EN_ROUTE, DeliveryAssignment::DELIVERED, DeliveryAssignment::FAILED]),
                'shipment-statuses' => static fn (): array => self::enum(Shipment::STATUSES),
                'drivers' => static fn (): array => DB::connection('tenant')->table('drivers')->where('status', Driver::ACTIVE)->orderBy('name')->get(['id', 'name', 'is_available', 'vehicle_type'])
                    ->map(static fn ($d): array => ['value' => (int) $d->id, 'label' => $d->name, 'meta' => ['is_available' => (bool) $d->is_available, 'vehicle_type' => $d->vehicle_type]])
                    ->all(),
                'promotions' => static fn (): array => DB::connection('tenant')->table('promotions')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')
                    ->get(['id', 'name', 'trigger', 'scope'])
                    ->map(static fn ($p): array => ['value' => (int) $p->id, 'label' => $p->name, 'meta' => ['trigger' => $p->trigger, 'scope' => $p->scope]])
                    ->all(),
                'promotion-enums' => static fn (): array => [
                    ...array_map(static fn (array $e): array => [...$e, 'meta' => ['group' => 'trigger']], self::enum([Promotion::AUTOMATIC, Promotion::COUPON])),
                    ...array_map(static fn (array $e): array => [...$e, 'meta' => ['group' => 'scope']], self::enum(Promotion::SCOPES)),
                    ...array_map(static fn (array $e): array => [...$e, 'meta' => ['group' => 'discount_type']], self::enum(Promotion::DISCOUNT_TYPES)),
                    ...array_map(static fn (array $e): array => [...$e, 'meta' => ['group' => 'target_type']], self::enum(PromotionTarget::TYPES)),
                ],
                'inventory-movement-types' => static fn (): array => self::enum(InventoryMovement::TYPES),
                'tax-rates' => static fn (Request $r): array => DB::connection('tenant')->table('tax_rates')
                    ->when(is_numeric($r->query('country_id')), static fn ($q) => $q->where('country_id', (int) $r->query('country_id')))
                    ->orderBy('name')->get(['id', 'name', 'country_id', 'state_id', 'tax_class', 'rate_percentage', 'is_active'])
                    ->map(static fn ($t): array => ['value' => (int) $t->id, 'label' => $t->name, 'meta' => [
                        'country_id' => (int) $t->country_id, 'state_id' => $t->state_id === null ? null : (int) $t->state_id,
                        'tax_class' => $t->tax_class, 'rate_percentage' => bcadd((string) $t->rate_percentage, '0', 4), 'is_active' => (bool) $t->is_active,
                    ]])->all(),
                'order-payment-methods' => static fn (): array => self::enum(OrderPayment::MANUAL_METHODS),
                'dashboard-sections' => static fn (Request $r): array => $r->user() instanceof User
                    ? array_map(static fn (array $s): array => ['value' => $s['key'], 'label' => $s['label']], app(TenantDashboardService::class)->sections($r->user()))
                    : [],
                'display-formats' => $displayFormats,
                'dashboard-ranges' => $dashboardRanges,
            ],

            default => [],
        };
    }

    /**
     * An enum-backed lookup from the model's own value list, so it never
     * drifts from validation.
     *
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private static function enum(array $values): array
    {
        return array_map(static fn (string $v): array => ['value' => $v, 'label' => ucfirst(str_replace('_', ' ', $v))], array_values($values));
    }

    private function requiredId(Request $request, string $parameter): int
    {
        $value = $request->query($parameter);

        if (! is_numeric($value) || (int) $value < 1) {
            throw ValidationException::withMessages([$parameter => ["The {$parameter} parameter is required."]]);
        }

        return (int) $value;
    }
}
