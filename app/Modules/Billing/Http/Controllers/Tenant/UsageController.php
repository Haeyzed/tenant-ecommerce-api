<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/billing/usage (spec §11.13).
 */
final class UsageController extends Controller
{
    public function index(PlanLimitService $limits): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return APIResponse::success($limits->getUsageSummary($tenant));
    }
}
