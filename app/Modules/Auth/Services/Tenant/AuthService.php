<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Tenant;

use App\Modules\Auth\Support\CredentialCheck;
use App\Modules\Auth\Support\DisplayPreferences;
use App\Modules\Auth\Support\TokenIssuer;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Staff authentication (spec §10.3). Tenant context only.
 */
final readonly class AuthService
{
    public function __construct(
        private DisplayPreferences $display,
        private FeatureAccessService $features,
        private TenantSettingsService $settings,
        private LegalDocumentService $legal,
    ) {}

    /**
     * @return array{token: string, token_type: string, expires_at: string|null, user: User}
     */
    public function login(string $email, string $password, string $device = 'api'): array
    {
        $user = User::query()->where('email', strtolower($email))->first();

        CredentialCheck::assert($user, $password);
        /** @var User $user */
        if (! $user->is_active) {
            throw ApiException::forbidden('account_disabled', 'This account is deactivated.');
        }

        $user->forceFill(['last_login_at' => now()])->save();

        ActivityRecorder::tenant('auth', 'Staff user logged in', $user, [], $user);

        return TokenIssuer::issue($user, 'staff', $device) + ['user' => $user];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /**
     * A new token replaces the current one (spec §10.2).
     *
     * @return array{token: string, token_type: string, expires_at: string|null, user: User}
     */
    public function refreshToken(User $user): array
    {
        $current = $user->currentAccessToken();
        $issued = TokenIssuer::issue($user, 'staff', $current instanceof PersonalAccessToken ? $current->name : 'api');

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        return $issued + ['user' => $user];
    }

    public function forgotPassword(string $email): void
    {
        Password::broker('users')->sendResetLink(['email' => strtolower($email), 'is_active' => true]);
    }

    public function resetPassword(string $token, string $email, string $newPassword): void
    {
        $status = Password::broker('users')->reset(
            ['email' => strtolower($email), 'token' => $token, 'password' => $newPassword],
            static function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }
    }

    /**
     * Changing the password revokes every other token of the user.
     */
    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => [__('auth.password')]]);
        }

        $user->forceFill(['password' => $new])->save();

        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when($currentToken instanceof PersonalAccessToken, static fn ($q) => $q->where('id', '!=', $currentToken->getKey()))
            ->delete();

        ActivityRecorder::tenant('auth', 'Staff user changed password', $user, [], $user);
    }

    /**
     * @param  array{date_format?: string|null, time_format?: string|null}  $preferences
     */
    public function updatePreferences(User $user, array $preferences): User
    {
        $user->forceFill(['preferences' => array_merge((array) $user->preferences, $preferences)])->save();

        return $user;
    }

    /**
     * GET /api/admin/auth/me (spec §10.5).
     *
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        /** @var Tenant $tenant */
        $tenant = tenant();
        $isOwner = $user->isOwner();

        $profile = [
            'user' => $user,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'is_owner' => $isOwner,
            'modules' => array_map(static fn ($state) => $state->value, $this->features->states($tenant)),
            'display' => $this->display->forStaff($user->preferences),
            'payment_mode' => (string) $this->settings->get('payment_mode'),
        ];

        if ($isOwner) {
            $subscription = Subscription::query()->where('tenant_id', $tenant->getTenantKey())->orderByDesc('id')->first();

            $profile['tenant_status'] = $tenant->status->value;
            $profile['subscription_status'] = $subscription?->status->value;
            $profile['pending_legal_documents'] = $this->legal->pendingForTenant($tenant)
                ->map(static fn ($document): array => [
                    'id' => $document->id,
                    'document_type' => $document->document_type,
                    'version' => $document->version,
                    'title' => $document->title,
                ])->values()->all();
        }

        return $profile;
    }
}
