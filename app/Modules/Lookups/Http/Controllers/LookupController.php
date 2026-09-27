<?php

declare(strict_types=1);

namespace App\Modules\Lookups\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Lookups\Support\LookupRegistry;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one generic lookup endpoint of every context (spec §45). The route
 * carries its context as a default; an unknown key is 404. A module's
 * lookup answers only while the module is readable (its own 403, §11.9).
 */
final class LookupController extends Controller
{
    public function show(Request $request, string $key, LookupRegistry $lookups, FeatureAccessService $features): JsonResponse
    {
        $context = (string) $request->route()?->defaults['lookup_context'];

        abort_unless($lookups->has($context, $key), 404);

        $feature = $lookups->feature($context, $key);
        $tenant = tenant();

        if ($feature !== null && (! $tenant instanceof Tenant || ! $features->canRead($tenant, $feature))) {
            $state = $tenant instanceof Tenant ? $features->state($tenant, $feature) : null;

            throw ApiException::forbidden($state?->errorCode() ?? 'feature_unavailable', 'This module is not available.', ['module' => $feature, 'state' => $state?->value]);
        }

        return APIResponse::success($lookups->resolve($context, $key, $request));
    }
}
