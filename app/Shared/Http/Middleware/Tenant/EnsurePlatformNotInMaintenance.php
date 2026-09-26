<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Tenant;

use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * platform.maintenance (spec §18.4): 503 on every tenant-domain request
 * while platform_settings.maintenance_mode is on. Tenant webhook routes do
 * not use this middleware and keep being accepted.
 */
final readonly class EnsurePlatformNotInMaintenance
{
    public function __construct(private PlatformSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) $this->settings->get('maintenance_mode', false)) {
            throw new ApiException('maintenance', 'The platform is undergoing maintenance. Please try again shortly.', 503);
        }

        return $next($request);
    }
}
