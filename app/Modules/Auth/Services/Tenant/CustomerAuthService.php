<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Tenant;

use App\Modules\Auth\Support\CredentialCheck;
use App\Modules\Auth\Support\EmailVerificationLink;
use App\Modules\Auth\Support\TokenIssuer;
use App\Modules\Customers\Events\CustomerAuthenticated;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Customer authentication (spec §10.3). Register and login announce the
 * guest token, so the cart can merge the guest cart (§38.2).
 */
final readonly class CustomerAuthService
{
    public function __construct(
        private CustomerService $customers,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{token: string, token_type: string, expires_at: string|null, customer: Customer}
     */
    public function register(array $data, ?string $guestToken = null, string $device = 'api'): array
    {
        $customer = $this->customers->registerCustomer($data);

        $customer->sendEmailVerificationNotification();
        $this->notifications->dispatch('customer.welcome', $customer, [
            'customer_name' => $customer->name,
            'store_name' => Customer::storeName(),
        ]);

        $customer->forceFill(['last_login_at' => now()])->save();
        CustomerAuthenticated::dispatch($customer, $guestToken, true);

        return TokenIssuer::issue($customer, 'customer', $device) + ['customer' => $customer];
    }

    /**
     * @return array{token: string, token_type: string, expires_at: string|null, customer: Customer}
     */
    public function login(string $email, string $password, ?string $guestToken = null, string $device = 'api'): array
    {
        $customer = Customer::query()->where('email', strtolower(trim($email)))->whereNull('anonymized_at')->first();

        CredentialCheck::assert($customer, $password);
        /** @var Customer $customer */
        if (! $customer->is_active) {
            throw ApiException::forbidden('account_disabled', 'This account is disabled.');
        }

        $customer->forceFill(['last_login_at' => now()])->save();
        CustomerAuthenticated::dispatch($customer, $guestToken, false);

        return TokenIssuer::issue($customer, 'customer', $device) + ['customer' => $customer];
    }

    public function logout(Customer $customer): void
    {
        $customer->currentAccessToken()?->delete();
    }

    public function verifyEmail(string $id, string $hash, int $expires, string $signature): Customer
    {
        $customer = Customer::query()->find($id);

        if ($customer === null || $customer->email === null
            || ! EmailVerificationLink::isValid('customer', $id, $hash, $expires, $signature, $customer->email)) {
            throw ApiException::unprocessable('verification_link_invalid', 'This verification link is invalid or has expired.');
        }

        if (! $customer->hasVerifiedEmail()) {
            $customer->markEmailAsVerified();
        }

        return $customer;
    }

    public function resendVerificationEmail(Customer $customer): void
    {
        if ($customer->email !== null && ! $customer->hasVerifiedEmail()) {
            $customer->sendEmailVerificationNotification();
        }
    }

    /**
     * Always succeeds from the caller's view, so account existence is not
     * disclosed.
     */
    public function forgotPassword(string $email): void
    {
        $customer = Customer::query()->where('email', strtolower(trim($email)))->whereNull('anonymized_at')->where('is_active', true)->first();

        if ($customer !== null) {
            Password::broker('customers')->sendResetLink(['email' => $customer->email]);
        }
    }

    public function resetPassword(string $token, string $email, string $newPassword): void
    {
        $status = Password::broker('customers')->reset(
            ['email' => strtolower(trim($email)), 'token' => $token, 'password' => $newPassword],
            static function (Customer $customer, string $password): void {
                $customer->forceFill(['password' => $password])->save();

                if (! $customer->hasVerifiedEmail()) {
                    $customer->markEmailAsVerified();
                }

                $customer->tokens()->delete();
                event(new PasswordReset($customer));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }
    }

    /**
     * Revokes every other session of the customer.
     */
    public function changePassword(Customer $customer, string $current, string $new): void
    {
        if ($customer->password === null || ! Hash::check($current, $customer->password)) {
            throw ValidationException::withMessages(['current_password' => [__('auth.password')]]);
        }

        $customer->forceFill(['password' => $new])->save();

        $currentToken = $customer->currentAccessToken();
        $customer->tokens()
            ->when($currentToken instanceof PersonalAccessToken, static fn ($q) => $q->where('id', '!=', $currentToken->getKey()))
            ->delete();
    }
}
