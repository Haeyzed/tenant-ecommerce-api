<?php

declare(strict_types=1);

namespace App\Modules\Access\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\RoleService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Tenant roles and permissions (spec §12.3).
 */
final class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->roles->listRoles()->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->present($this->roles->createRole($request->all())));
    }

    public function update(Request $request, int $role): JsonResponse
    {
        return APIResponse::success($this->present($this->roles->updateRole($this->find($role), $request->all())), 'Role updated');
    }

    public function destroy(int $role): JsonResponse
    {
        $this->roles->deleteRole($this->find($role));

        return APIResponse::noContent('Role deleted');
    }

    public function syncPermissions(Request $request, int $role): JsonResponse
    {
        $names = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'max:128']])['permissions'];

        return APIResponse::success($this->present($this->roles->assignPermissionsToRole($this->find($role), array_values($names))), 'Permissions updated');
    }

    private function find(int $id): Role
    {
        return Role::query()->where('guard_name', RoleService::GUARD)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'protected' => in_array($role->name, RoleService::PROTECTED_ROLES, true),
            'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
        ];
    }
}
