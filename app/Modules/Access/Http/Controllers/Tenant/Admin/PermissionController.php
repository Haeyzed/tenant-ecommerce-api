<?php

declare(strict_types=1);

namespace App\Modules\Access\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\RoleService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/permissions (spec §12.3). An "index" action so the
 * derived permission is permissions.view (§12.4); "RoleController@permissions"
 * would derive no name at all.
 */
final class PermissionController extends Controller
{
    public function index(RoleService $roles): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return APIResponse::success($roles->listPermissions($tenant));
    }
}
