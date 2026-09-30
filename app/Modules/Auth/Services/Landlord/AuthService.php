<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Landlord;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Auth\Support\CredentialCheck;
use App\Modules\Auth\Support\DisplayPreferences;
use App\Modules\Auth\Support\EmailVerificationLink;
use App\Modules\Auth\Support\TokenIssuer;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Platform-user authentication (spec §10.3).
 */
final readonly class AuthService
{
    public function __construct(private DisplayPreferences $display) {}

    /**
     * @return array{token: string, token_type: string, expires_at: string|null, user: PlatformUser}
     */
    public function login(string $email, string $password, string $device = 'api'): array
    {
        $user = PlatformUser::query()->where('email', strtolower($email))->first();

        CredentialCheck::assert($user, $password);
        /** @var PlatformUser $user */
        if (! $user->is_active) {
            throw ApiException::forbidden('account_disabled', 'This account is disabled.');
        }

        $user->forceFill(['last_login_at' => now()])->save();

        ActivityRecorder::landlord('auth', 'Platform user logged in', $user, [], $user);

        return TokenIssuer::issue($user, 'platform', $device) + ['user' => $user];
    }

    public function logout(PlatformUser $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /**
     * A new token replaces the current one (spec §10.2, BG-10).
     *
     * @return array{token: string, token_type: string, expires_at: string|null, user: PlatformUser}
     */
    public function refreshToken(PlatformUser $user): array
    {
        $current = $user->currentAccessToken();
        $issued = TokenIssuer::issue($user, 'platform', $current instanceof PersonalAccessToken ? $current->name : 'api');

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        return $issued + ['user' => $user];
    }

    /**
     * Changing the password revokes every other token of the user (BG-10).
     */
    public function changePassword(PlatformUser $user, string $current, string $new): void
    {
        if (! Hash::check($current, (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => [__('auth.password')]]);
        }

        $user->forceFill(['password' => $new])->save();

        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when($currentToken instanceof PersonalAccessToken, static fn ($q) => $q->where('id', '!=', $currentToken->getKey()))
            ->delete();

        ActivityRecorder::landlord('auth', 'Platform user changed password', $user, [], $user);
    }

    public function verifyEmail(string $id, string $hash, int $expires, string $signature): PlatformUser
    {
        $user = PlatformUser::query()->find($id);

        if ($user === null || ! EmailVerificationLink::isValid('platform', $id, $hash, $expires, $signature, $user->email)) {
            throw ApiException::unprocessable('verification_link_invalid', 'This verification link is invalid or has expired.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    public function resendVerificationEmail(PlatformUser $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }

    /**
     * Always succeeds from the caller's view, so account existence is not
     * disclosed; throttling is enforced by the broker and the route limiter.
     */
    public function forgotPassword(string $email): void
    {
        Password::broker('platform_users')->sendResetLink(['email' => strtolower($email), 'is_active' => true]);
    }

    public function resetPassword(string $token, string $email, string $newPassword): void
    {
        $status = Password::broker('platform_users')->reset(
            ['email' => strtolower($email), 'token' => $token, 'password' => $newPassword],
            static function (PlatformUser $user, string $password): void {
                $user->forceFill(['password' => $password])->save();

                // A reset proves control of the address.
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }

                $user->tokens()->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }
    }

    /**
     * @param  array{date_format?: string|null, time_format?: string|null}  $preferences
     */
    public function updatePreferences(PlatformUser $user, array $preferences): PlatformUser
    {
        $user->forceFill(['preferences' => array_merge((array) $user->preferences, $preferences)])->save();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(PlatformUser $user): array
    {
        return [
            'user' => $user,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'display' => $this->display->forPlatformUser($user->preferences),
        ];
    }
}
