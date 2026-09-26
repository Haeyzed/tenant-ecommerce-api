<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff users (spec §25.4).
 */
final class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->users->listUsers($filters)->through(fn (User $u): array => $this->present($u, false)));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->present($this->users->createUser($request->all(), $this->actor($request)), false), 'Staff user created');
    }

    public function show(User $user): JsonResponse
    {
        return APIResponse::success($this->present($this->users->getUser($user), true));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        return APIResponse::success($this->present($this->users->updateUser($user, $request->all(), $this->actor($request)), false), 'Staff user updated');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->users->deleteUser($user, $this->actor($request));

        return APIResponse::noContent('Staff user deleted');
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        return APIResponse::success($this->present($this->users->deactivateUser($user, $this->actor($request)), false), 'Staff user deactivated');
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        return APIResponse::success($this->present($this->users->reactivateUser($user, $this->actor($request)), false), 'Staff user reactivated');
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $roles = $request->validate(['roles' => ['present', 'array'], 'roles.*' => ['string']])['roles'];

        return APIResponse::success($this->present($this->users->syncRoles($user, $roles, $this->actor($request)), true), 'Roles updated');
    }

    public function syncPermissions(Request $request, User $user): JsonResponse
    {
        $permissions = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string']])['permissions'];

        return APIResponse::success($this->present($this->users->syncDirectPermissions($user, $permissions, $this->actor($request)), true), 'Direct permissions updated');
    }

    /**
     * {user} is the new owner; the caller must be the current owner and
     * confirm with their password.
     */
    public function transferOwnership(Request $request, User $user): JsonResponse
    {
        $password = (string) $request->validate(['current_password' => ['required', 'string', 'max:255']])['current_password'];

        return APIResponse::success($this->present($this->users->transferOwnership($user, $this->actor($request), $password), true), 'Ownership transferred');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, bool $withPermissions): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'is_owner' => $user->relationLoaded('roles') ? $user->roles->contains('name', 'owner') : $user->isOwner(),
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name')->values()->all() : $user->getRoleNames()->values()->all(),
            'direct_permissions' => $withPermissions ? $user->getDirectPermissions()->pluck('name')->sort()->values()->all() : null,
            'effective_permissions' => $withPermissions ? $this->users->getEffectivePermissions($user) : null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
