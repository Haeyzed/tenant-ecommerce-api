<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Services;

use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Support\AffiliateIdentity;
use App\Modules\Auth\Support\CredentialCheck;
use App\Modules\Auth\Support\EmailVerificationLink;
use App\Modules\Auth\Support\TokenIssuer;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Affiliate authentication (spec §10.3). Pending, approved and suspended
 * affiliates may sign in to see their status and history; rejected and
 * closed ones may not.
 */
final readonly class AffiliateAuthService
{
    private const array CAN_SIGN_IN = [Affiliate::PENDING, Affiliate::APPROVED, Affiliate::SUSPENDED];

    public function __construct(private AffiliateService $affiliates) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data, Request $request): Affiliate
    {
        return $this->affiliates->apply($data, $request);
    }

    /**
     * @return array{token: string, token_type: string, expires_at: string|null, affiliate: Affiliate}
     */
    public function login(string $email, string $password, Request $request, string $device = 'api'): array
    {
        $affiliate = Affiliate::query()->where('email', strtolower(trim($email)))->first();

        CredentialCheck::assert($affiliate, $password);
        /** @var Affiliate $affiliate */
        if (! in_array($affiliate->status, self::CAN_SIGN_IN, true)) {
            throw ApiException::forbidden('account_disabled', 'This affiliate account is no longer active.');
        }

        $ipHash = AffiliateIdentity::ipHash($request->ip());
        $affiliate->forceFill(['last_login_at' => now(), 'last_login_ip_hash' => $ipHash])->save();

        // The IP hash history feeds the affiliate_ip_match review flag (§21A.7).
        ActivityRecorder::landlord('auth', 'Affiliate logged in', $affiliate, ['ip_hash' => $ipHash], $affiliate);

        return TokenIssuer::issue($affiliate, 'affiliate', $device) + ['affiliate' => $affiliate];
    }

    public function logout(Affiliate $affiliate): void
    {
        $affiliate->currentAccessToken()?->delete();
    }

    public function verifyEmail(string $id, string $hash, int $expires, string $signature): Affiliate
    {
        $affiliate = Affiliate::query()->find($id);

        if ($affiliate === null || ! EmailVerificationLink::isValid('affiliate', $id, $hash, $expires, $signature, $affiliate->email)) {
            throw ApiException::unprocessable('verification_link_invalid', 'This verification link is invalid or has expired.');
        }

        if (! $affiliate->hasVerifiedEmail()) {
            $affiliate->markEmailAsVerified();
            $this->affiliates->announceApplication($affiliate);
        }

        return $affiliate;
    }

    public function resendVerificationEmail(Affiliate $affiliate): void
    {
        if (! $affiliate->hasVerifiedEmail()) {
            $affiliate->sendEmailVerificationNotification();
        }
    }

    /**
     * Always succeeds from the caller's view, so account existence is not
     * disclosed.
     */
    public function forgotPassword(string $email): void
    {
        $affiliate = Affiliate::query()->where('email', strtolower(trim($email)))->first();

        if ($affiliate !== null && in_array($affiliate->status, self::CAN_SIGN_IN, true)) {
            Password::broker('affiliates')->sendResetLink(['email' => $affiliate->email]);
        }
    }

    public function resetPassword(string $token, string $email, string $newPassword): void
    {
        $status = Password::broker('affiliates')->reset(
            ['email' => strtolower(trim($email)), 'token' => $token, 'password' => $newPassword],
            static function (Affiliate $affiliate, string $password): void {
                $affiliate->forceFill(['password' => $password])->save();

                if (! $affiliate->hasVerifiedEmail()) {
                    $affiliate->markEmailAsVerified();
                }

                $affiliate->tokens()->delete();
                event(new PasswordReset($affiliate));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }
    }

    /**
     * Revokes every other session of the affiliate.
     */
    public function changePassword(Affiliate $affiliate, string $current, string $new): void
    {
        if (! Hash::check($current, $affiliate->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is not correct.']]);
        }

        $affiliate->forceFill(['password' => $new])->save();

        $currentTokenId = $affiliate->currentAccessToken()?->getKey();
        $affiliate->tokens()->when($currentTokenId !== null, static fn ($q) => $q->whereKeyNot($currentTokenId))->delete();

        ActivityRecorder::landlord('auth', 'Affiliate changed password', $affiliate, [], $affiliate);
    }
}
