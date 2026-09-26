<?php

declare(strict_types=1);

namespace App\Modules\Access\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Access\Services\PlatformUserService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform team management (spec §12.2). Super-admin only by permission.
 */
final class PlatformUserController extends Controller
{
    public function __construct(private readonly PlatformUserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'string', 'max:64'],
        ]);

        $page = PlatformUser::query()
            ->with('roles')
            ->when($filters['search'] ?? null, static function ($q, string $v): void {
                $like = '%'.addcslashes($v, '%_\\').'%';
                $q->where(static fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($filters['role'] ?? null, static fn ($q, $v) => $q->withPlatformRole($v))
            ->orderBy('name')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))))
            ->through(fn (PlatformUser $u): array => $this->present($u));

        return APIResponse::success($page);
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->present($this->users->createPlatformUser($request->all(), $this->actor($request))), 'Invitation sent');
    }

    public function update(Request $request, PlatformUser $user): JsonResponse
    {
        return APIResponse::success($this->present($this->users->updatePlatformUser($user, $request->all(), $this->actor($request))), 'Platform user updated');
    }

    public function deactivate(Request $request, PlatformUser $user): JsonResponse
    {
        return APIResponse::success($this->present($this->users->deactivatePlatformUser($user, $this->actor($request))), 'Platform user deactivated');
    }

    public function assignRole(Request $request, PlatformUser $user): JsonResponse
    {
        $role = $request->validate(['role' => ['required', 'string', 'max:64']])['role'];

        return APIResponse::success($this->present($this->users->assignRole($user, $role, $this->actor($request))), 'Role assigned');
    }

    public function revokeRole(Request $request, PlatformUser $user, string $role): JsonResponse
    {
        return APIResponse::success($this->present($this->users->revokeRole($user, $role, $this->actor($request))), 'Role revoked');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'has_password' => $user->password !== null,
            'roles' => $user->getRoleNames()->values()->all(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function actor(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
