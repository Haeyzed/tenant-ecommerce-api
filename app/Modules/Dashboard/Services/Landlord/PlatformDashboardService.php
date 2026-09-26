<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Landlord;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsCache;
use App\Shared\Metrics\SectionResult;
use Closure;
use Illuminate\Contracts\Container\Container;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Composes the landlord dashboard (spec §22.3, §22.6, §22.8) from the
 * section registry in config/dashboards.php: checks the section's data
 * permission, runs the parts the viewer may see and caches the result.
 */
final readonly class PlatformDashboardService
{
    public const string GUARD = 'platform';

    public function __construct(
        private Container $container,
        private MetricsCache $cache,
        private PlatformSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated range parameters
     */
    public function range(array $input): DateRange
    {
        return DateRange::fromInput($input, $this->settings->timezone());
    }

    /**
     * The sections this user may see, so frontends never render an empty
     * or forbidden card.
     *
     * @return list<array{key: string, label: string}>
     */
    public function sections(PlatformUser $user): array
    {
        $visible = [];

        foreach ($this->registry() as $key => $section) {
            if ($this->allowed($user, $section['permission'] ?? null)) {
                $visible[] = ['key' => $key, 'label' => (string) $section['label']];
            }
        }

        return $visible;
    }

    public function section(string $section, DateRange $range, PlatformUser $user): SectionResult
    {
        $definition = $this->definition($section, $user);
        $result = new SectionResult;

        foreach ($definition['parts'] as $part) {
            if (! $this->allowed($user, $part[2] ?? null)) {
                continue;
            }

            $result = $result->merge($this->container->make($part[0])->{$part[1]}($range));
        }

        $alerts = $section === 'overview' ? $this->criticalAlerts($user) : $this->alerts($definition);

        return $result->merge(new SectionResult(alerts: $alerts));
    }

    /**
     * The section payload, cached per section, permission set and range
     * (§22.7).
     *
     * @return array{data: array<string, mixed>, cached: bool, generated_at: string}
     */
    public function cachedSection(string $section, DateRange $range, PlatformUser $user): array
    {
        $this->definition($section, $user);

        return $this->cache->remember('section', 'landlord.'.$section, $range, $this->shapingPermissions($user),
            fn (): array => $this->section($section, $range, $user)->toArray($section, $range));
    }

    /**
     * A list screen's KPI strip (§22.4), cached for two minutes. The route
     * permission (the resource's view permission) has already been checked.
     *
     * @param  Closure(DateRange): list<KpiValue>  $compute
     * @return array{data: array{kpis: list<array<string, mixed>>}, cached: bool, generated_at: string}
     */
    public function contextualKpis(string $resource, DateRange $range, Closure $compute): array
    {
        return $this->cache->remember('kpis', 'landlord.'.$resource, $range, [], static fn (): array => [
            'kpis' => array_map(static fn (KpiValue $k): array => $k->jsonSerialize(), $compute($range)),
        ]);
    }

    /**
     * @return array{label: string, permission?: string|null, parts: list<array{0: class-string, 1: string, 2?: string}>, alerts: list<array{0: class-string, 1: string}>}
     */
    private function definition(string $section, PlatformUser $user): array
    {
        $definition = $this->registry()[$section] ?? throw new ApiException('dashboard_section_not_found', 'This dashboard section does not exist.', 404);

        if (! $this->allowed($user, $definition['permission'] ?? null)) {
            throw ApiException::forbidden('forbidden', 'You are not allowed to view this dashboard section.', ['permission' => $definition['permission']]);
        }

        return $definition;
    }

    /**
     * @param  array{alerts: list<array{0: class-string, 1: string}>}  $definition
     * @return list<Alert>
     */
    private function alerts(array $definition): array
    {
        $alerts = [];

        foreach ($definition['alerts'] as [$class, $method]) {
            array_push($alerts, ...$this->container->make($class)->{$method}());
        }

        return $alerts;
    }

    /**
     * Critical alerts of every other section the user may see, each alert
     * key once.
     *
     * @return list<Alert>
     */
    private function criticalAlerts(PlatformUser $user): array
    {
        $alerts = [];

        foreach ($this->registry() as $key => $definition) {
            if ($key === 'overview' || ! $this->allowed($user, $definition['permission'] ?? null)) {
                continue;
            }

            foreach ($this->alerts($definition) as $alert) {
                if ($alert->severity === Alert::CRITICAL) {
                    $alerts[$alert->key] ??= $alert;
                }
            }
        }

        return array_values($alerts);
    }

    /**
     * The viewer's permissions that change what a section contains.
     *
     * @return list<string>
     */
    private function shapingPermissions(PlatformUser $user): array
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

    private function allowed(PlatformUser $user, ?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission, self::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function registry(): array
    {
        return (array) config('dashboards.landlord', []);
    }
}
