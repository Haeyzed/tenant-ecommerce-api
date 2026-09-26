<?php

declare(strict_types=1);

namespace App\Modules\Access\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * The platform's own team (spec §12.2). Users set their own password from
 * the invitation link; no password is ever chosen for them.
 */
final readonly class PlatformUserService
{
    public const string GUARD = 'platform';

    public function __construct(private NotificationDispatchService $notifications) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPlatformUser(array $data, PlatformUser $by): PlatformUser
    {
        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('landlord.platform_users', 'email')],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('landlord.roles', 'name')->where('guard_name', self::GUARD)],
        ])->validate();

        $user = DB::connection('landlord')->transaction(function () use ($validated, $by): PlatformUser {
            /** @var PlatformUser $user */
            $user = PlatformUser::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => null,
                'is_active' => true,
            ]);

            $user->syncRoles($validated['roles'] ?? []);
            ActivityRecorder::landlord('platform_users', "Platform user {$user->email} created", $user, ['roles' => $validated['roles'] ?? []], $by);

            return $user;
        });

        $token = Password::broker('platform_users')->createToken($user);

        $this->notifications->dispatch('platform_user.invited', $user, [
            'name' => $user->name,
            'invited_by' => $by->name,
            'set_password_url' => FrontendUrl::platformAdmin('/reset-password', ['token' => $token, 'email' => $user->email]),
            'expires_in_minutes' => (int) config('auth.passwords.platform_users.expire', 60),
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePlatformUser(PlatformUser $user, array $data, PlatformUser $by): PlatformUser
    {
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim((string) $data['email']));
        }

        $validated = validator($data, [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email:rfc', 'max:255', Rule::unique('landlord.platform_users', 'email')->ignore($user->id)],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        if (($validated['is_active'] ?? true) === false) {
            $this->assertNotLastSuperAdmin($user);
        }

        $user->fill($validated);

        // A changed address must be verified again.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
        ActivityRecorder::landlord('platform_users', "Platform user {$user->email} updated", $user, array_keys($validated), $by);

        if (($validated['is_active'] ?? true) === false) {
            $user->tokens()->delete();
        }

        return $user;
    }

    public function deactivatePlatformUser(PlatformUser $user, PlatformUser $by): PlatformUser
    {
        if ($user->is($by)) {
            throw ApiException::unprocessable('cannot_deactivate_self', 'You cannot deactivate your own account.');
        }

        $this->assertNotLastSuperAdmin($user);

        $user->forceFill(['is_active' => false])->save();
        $user->tokens()->delete();
        ActivityRecorder::landlord('platform_users', "Platform user {$user->email} deactivated", $user, [], $by);

        return $user;
    }

    public function assignRole(PlatformUser $user, string $role, PlatformUser $by): PlatformUser
    {
        $model = Role::query()->where('name', $role)->where('guard_name', self::GUARD)->first()
            ?? throw ApiException::unprocessable('role_unknown', "Unknown platform role [{$role}].");

        $user->assignRole($model);
        ActivityRecorder::landlord('platform_users', "Role {$role} assigned to {$user->email}", $user, [], $by);

        return $user;
    }

    public function revokeRole(PlatformUser $user, string $role, PlatformUser $by): PlatformUser
    {
        if ($role === 'super-admin') {
            $this->assertNotLastSuperAdmin($user);
        }

        $user->removeRole($role);
        ActivityRecorder::landlord('platform_users', "Role {$role} revoked from {$user->email}", $user, [], $by);

        return $user;
    }

    /**
     * The platform can never lock itself out.
     */
    private function assertNotLastSuperAdmin(PlatformUser $user): void
    {
        if (! $user->hasRole('super-admin', self::GUARD)) {
            return;
        }

        $others = PlatformUser::query()
            ->withPlatformRole('super-admin')
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->count();

        if ($others === 0) {
            throw ApiException::unprocessable('last_super_admin', 'At least one active super-admin must remain.');
        }
    }
}
