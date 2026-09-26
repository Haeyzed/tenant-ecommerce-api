<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

final class TenantModuleController extends Controller
{
    public function index(Tenant $tenant, FeatureAccessService $features): JsonResponse
    {
        return APIResponse::success($features->listEffectiveModules($tenant));
    }
}
