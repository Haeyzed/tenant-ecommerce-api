<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/platform/config: every public platform setting (§13.2).
 */
final class PlatformConfigController extends Controller
{
    public function show(PlatformSettingsService $settings): JsonResponse
    {
        return APIResponse::success($settings->publicConfig());
    }
}
