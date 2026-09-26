<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\StorefrontConfigService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/storefront/config (spec §13.5).
 */
final class StorefrontConfigController extends Controller
{
    public function show(StorefrontConfigService $config): JsonResponse
    {
        return APIResponse::success($config->publicConfig());
    }
}
