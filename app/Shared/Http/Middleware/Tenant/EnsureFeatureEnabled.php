<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Tenant;

use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * feature:{key} and feature:{key},wind-down (spec §11.9, §71).
 */
final readonly class EnsureFeatureEnabled
{
    public function __construct(
        private FeatureAccessService $features,
        private ModuleRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next, string $key, ?string $mode = null): Response
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw ApiException::forbidden('feature_unavailable', 'This feature is not available.', ['module' => $key]);
        }

        $state = $this->features->state($tenant, $key);

        if ($state === ModuleState::Enabled) {
            return $next($request);
        }

        if (in_array($state, [ModuleState::Disabled, ModuleState::Locked], true)
            && ($this->isWindDown($request, $key, $mode) || $this->isInactiveRead($request, $key))) {
            return $next($request);
        }

        $details = ['module' => $key, 'state' => $state->value];

        if (in_array($state, [ModuleState::Locked, ModuleState::Unavailable], true)) {
            $details['entitled_by_plans'] = $this->features->entitledByPlans($key);
        }

        $reason = $this->features->reason($tenant, $key);

        if ($reason !== null) {
            $details['reason'] = $reason;
        }

        throw ApiException::forbidden($state->errorCode(), match ($state) {
            ModuleState::Suspended => 'This module is suspended by the platform.',
            ModuleState::Locked => 'Your plan no longer includes this module. Its data is read-only.',
            ModuleState::Unavailable => 'Your plan does not include this module.',
            default => 'This module is not enabled.',
        }, $details);
    }

    private function isWindDown(Request $request, string $key, ?string $mode): bool
    {
        if ($mode === 'wind-down') {
            return true;
        }

        $name = $request->route()?->getName();

        return $name !== null && $this->registry->isWindDownRoute($key, $name);
    }

    /**
     * Admin GET/HEAD while disabled or locked, when the registry says "read
     * when inactive" (spec §11.5).
     */
    private function isInactiveRead(Request $request, string $key): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && in_array('tenant.admin', (array) $request->route()?->middleware(), true)
            && $this->registry->get($key)->readWhenInactive;
    }
}
