<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Tenant;

use App\Modules\Access\Services\RoleService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsCache;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Closure;
use Illuminate\Contracts\Container\Container;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Composes the tenant dashboard (spec §44) from config/dashboards.php:
 * checks the section's feature (readable, §11.5) and data permission,
 * runs the parts the viewer may see within their warehouse scope (§25.3)
 * and caches the result per permission set and scope (§22.7). Every
 * figure comes from the current tenant's database only.
 */
final readonly class TenantDashboardService
{
    public function __construct(
        private Container $container,
        private MetricsCache $cache,
        private TenantSettingsService $settings,
        private FeatureAccessService $features,
        private WarehouseService $warehouses,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated range parameters
     */
    public function range(array $input): DateRange
    {
        return DateRange::fromInput($input, (string) $this->settings->get('timezone', 'UTC'));
    }

    public function scope(User $user): MetricsScope
    {
        return new MetricsScope($this->warehouses->visibleIds($user));
    }

    /**
     * The sections this user may see (§22.3).
     *
     * @return list<array{key: string, label: string}>
     */
    public function sections(User $user): array
    {
        $visible = [];

        foreach ($this->registry() as $key => $section) {
            if ($this->readable($section['feature'] ?? null) && $this->allowed($user, $section['permission'] ?? null)) {
                $visible[] = ['key' => $key, 'label' => (string) $section['label']];
            }
        }

        return $visible;
    }

    public function section(string $section, DateRange $range, User $user): SectionResult
    {
        $definition = $this->definition($section, $user);
        $scope = $this->scope($user);
        $result = new SectionResult;

        foreach ($definition['parts'] as $part) {
            if (! $this->allowed($user, $part[2] ?? null)) {
                continue;
            }

            $result = $result->merge($this->container->make($part[0])->{$part[1]}($range, $scope));
        }

        $alerts = $section === 'overview' ? $this->criticalAlerts($user, $scope) : $this->alerts($definition, $scope);

        return $result->merge(new SectionResult(alerts: $alerts));
    }

    /**
     * @return array{data: array<string, mixed>, cached: bool, generated_at: string}
     */
    public function cachedSection(string $section, DateRange $range, User $user): array
    {
        $this->definition($section, $user);

        return $this->cache->remember('section', 'tenant.'.$section, $range, [...$this->shapingPermissions($user), $this->scope($user)->cacheKey()],
            fn (): array => $this->section($section, $range, $user)->toArray($section, $range));
    }

    /**
     * A list screen's KPI strip (§44.4): same providers, same scope. The
     * route has already checked the resource's view permission.
     *
     * @param  Closure(DateRange, MetricsScope): list<KpiValue>  $compute
     * @param  list<string>  $parameters  extra parameters that change the figures (e.g. warehouse)
     * @return array{data: array{kpis: list<array<string, mixed>>}, cached: bool, generated_at: string}
     */
    public function contextualKpis(string $resource, DateRange $range, User $user, Closure $compute, array $parameters = []): array
    {
        $scope = $this->scope($user);

        return $this->cache->remember('kpis', 'tenant.'.$resource, $range, [$scope->cacheKey(), ...$parameters], static fn (): array => [
            'kpis' => array_map(static fn (KpiValue $k): array => $k->jsonSerialize(), $compute($range, $scope)),
        ]);
    }

    /**
     * @return array{label: string, feature?: string|null, permission?: string|null, parts: list<array{0: class-string, 1: string, 2?: string}>, alerts: list<array{0: class-string, 1: string}>}
     */
    private function definition(string $section, User $user): array
    {
        $definition = $this->registry()[$section] ?? throw new ApiException('dashboard_section_not_found', 'This dashboard section does not exist.', 404);

        if (! $this->readable($definition['feature'] ?? null)) {
            // The module's own 403 (§11.9, §22.3).
            $tenant = tenant();
            $state = $tenant instanceof Tenant ? $this->features->state($tenant, (string) $definition['feature'])->value : 'unavailable';
            $code = $tenant instanceof Tenant ? $this->features->state($tenant, (string) $definition['feature'])->errorCode() : 'feature_unavailable';

            throw ApiException::forbidden($code, 'This module is not available.', ['module' => $definition['feature'], 'state' => $state]);
        }

        if (! $this->allowed($user, $definition['permission'] ?? null)) {
            throw ApiException::forbidden('forbidden', 'You are not allowed to view this dashboard section.', ['permission' => $definition['permission']]);
        }

        return $definition;
    }

    /**
     * @param  array{alerts: list<array{0: class-string, 1: string}>}  $definition
     * @return list<Alert>
     */
    private function alerts(array $definition, MetricsScope $scope): array
    {
        $alerts = [];

        foreach ($definition['alerts'] as [$class, $method]) {
            array_push($alerts, ...$this->container->make($class)->{$method}($scope));
        }

        return $alerts;
    }

    /**
     * The overview's alerts: every alert of each other section the user may
     * see, each key once (§44.3 "alerts from all sections the user may
     * see").
     *
     * @return list<Alert>
     */
    private function criticalAlerts(User $user, MetricsScope $scope): array
    {
        $alerts = [];

        foreach ($this->registry() as $key => $definition) {
            if ($key === 'overview' || ! $this->readable($definition['feature'] ?? null) || ! $this->allowed($user, $definition['permission'] ?? null)) {
                continue;
            }

            foreach ($this->alerts($definition, $scope) as $alert) {
                $alerts[$alert->key] ??= $alert;
            }
        }

        return array_values($alerts);
    }

    /**
     * @return list<string>
     */
    private function shapingPermissions(User $user): array
    {
        $names = [];

        foreach ($this->registry() as $definition) {
            $names[] = $definition['permission'] ?? null;

            foreach ($definition['parts'] as $part) {
                $names[] = $part[2] ?? null;
            }
        }

        return array_values(array_filter(array_unique(array_filter($names)), fn (string $p): bool => $this->allowed($user, $p)));
    }

    private function readable(?string $feature): bool
    {
        if ($feature === null || $feature === 'core') {
            return true;
        }

        $tenant = tenant();

        return $tenant instanceof Tenant && $this->features->canRead($tenant, $feature);
    }

    private function allowed(User $user, ?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission, RoleService::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function registry(): array
    {
        return (array) config('dashboards.tenant', []);
    }
}
