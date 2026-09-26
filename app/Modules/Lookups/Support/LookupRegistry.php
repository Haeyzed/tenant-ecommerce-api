<?php

declare(strict_types=1);

namespace App\Modules\Lookups\Support;

use App\Modules\Access\Services\RoleService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Modules\World\Services\WorldService;
use App\Shared\Metrics\DateRange;
use App\Shared\Support\DisplayFormat;
use Closure;
use Illuminate\Http\Request;
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
                'display-formats' => $displayFormats,
                'dashboard-ranges' => $dashboardRanges,
            ],

            default => [],
        };
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
