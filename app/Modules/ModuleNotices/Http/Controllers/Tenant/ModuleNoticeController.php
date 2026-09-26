<?php

declare(strict_types=1);

namespace App\Modules\ModuleNotices\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\ModuleNotices\Http\Resources\ModuleNoticeResource;
use App\Modules\ModuleNotices\Services\ModuleNoticeService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/module-notices: the current tenant's active notices (§18.5).
 */
final class ModuleNoticeController extends Controller
{
    public function index(Request $request, ModuleNoticeService $notices): JsonResponse
    {
        $module = $request->validate(['module' => ['sometimes', 'string', 'max:64']])['module'] ?? null;

        /** @var Tenant $tenant */
        $tenant = tenant();

        return APIResponse::success(ModuleNoticeResource::collection($notices->getActiveNoticesForTenant($tenant, $module)));
    }
}
