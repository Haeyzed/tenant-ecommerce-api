<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Tenant;

use App\Modules\Auth\Support\CredentialCheck;
use App\Modules\Auth\Support\TokenIssuer;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Services\SellerService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Seller authentication (spec §10.3; requires `marketplace`). A seller
 * registers as a pending application and can log in once approved.
 */
final readonly class SellerAuthService
{
    public function __construct(private SellerService $sellers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): Seller
    {
        return $this->sellers->registerSeller($data);
    }

    /**
     * @return array{token: string, token_type: string, expires_at: string|null, seller: Seller}
     */
    public function login(string $email, string $password, string $device = 'api'): array
    {
        $seller = Seller::query()->where('email', strtolower(trim($email)))->first();

        CredentialCheck::assert($seller, $password);
        /** @var Seller $seller */
        if ($seller->status !== Seller::APPROVED) {
            throw ApiException::forbidden('seller_not_approved', match ($seller->status) {
                Seller::PENDING => 'Your seller application is still under review.',
                Seller::SUSPENDED => 'This seller account is suspended.',
                default => 'This seller application was not approved.',
            }, ['status' => $seller->status]);
        }

        $seller->forceFill(['last_login_at' => now()])->save();

        return TokenIssuer::issue($seller, 'seller', $device) + ['seller' => $seller];
    }

    public function logout(Seller $seller): void
    {
        $seller->currentAccessToken()?->delete();
    }

    /**
     * Always succeeds from the caller's view, so account existence is not
     * disclosed. Only approved sellers receive a link.
     */
    public function forgotPassword(string $email): void
    {
        $seller = Seller::query()->where('email', strtolower(trim($email)))->where('status', Seller::APPROVED)->first();

        if ($seller !== null) {
            Password::broker('sellers')->sendResetLink(['email' => $seller->email]);
        }
    }

    public function resetPassword(string $token, string $email, string $newPassword): void
    {
        $status = Password::broker('sellers')->reset(
            ['email' => strtolower(trim($email)), 'token' => $token, 'password' => $newPassword],
            static function (Seller $seller, string $password): void {
                $seller->forceFill(['password' => $password])->save();
                $seller->tokens()->delete();
                event(new PasswordReset($seller));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }
    }

    /**
     * Revokes every other session of the seller.
     */
    public function changePassword(Seller $seller, string $current, string $new): void
    {
        if (! Hash::check($current, $seller->password)) {
            throw ValidationException::withMessages(['current_password' => [__('auth.password')]]);
        }

        $seller->forceFill(['password' => $new])->save();

        $currentToken = $seller->currentAccessToken();
        $seller->tokens()
            ->when($currentToken instanceof PersonalAccessToken, static fn ($q) => $q->where('id', '!=', $currentToken->getKey()))
            ->delete();
    }
}
