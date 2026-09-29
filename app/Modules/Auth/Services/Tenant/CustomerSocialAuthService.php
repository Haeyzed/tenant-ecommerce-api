<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Tenant;

use App\Modules\Auth\Support\TokenIssuer;
use App\Modules\Customers\Events\CustomerAuthenticated;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSocialAccount;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Customer sign-in with Google or Facebook (D-132), for an API-only store.
 *
 * start() returns the provider's authorisation URL and a one-time state
 * (10 minutes, bound to this tenant, the provider and the intent). The
 * provider sends the shopper to the platform's relay page, which forwards
 * code and state to the storefront, which posts them here. Socialite runs
 * stateless; the state is checked and consumed here instead.
 *
 * Matching: a linked identity signs its customer in. Otherwise an existing
 * account with the same email is linked only when the provider vouches for
 * the email (Google's email_verified) and the account is not an unverified
 * one that holds a password (a squatter's); a new account is created when
 * none exists; an identity without an email is refused. Disabled accounts
 * stay disabled. Provider tokens are never stored or logged.
 */
final readonly class CustomerSocialAuthService
{
    public const int STATE_TTL_MINUTES = 10;

    public const string LOGIN = 'login';

    public const string LINK = 'link';

    public function __construct(
        private CustomerService $customers,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * Providers configured on this platform.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_values(array_filter(CustomerSocialAccount::PROVIDERS, static fn (string $p): bool => (string) config("services.{$p}.client_id") !== ''
            && (string) config("services.{$p}.client_secret") !== '' && (string) config("services.{$p}.redirect") !== ''));
    }

    /**
     * @param  array<string, mixed>  $customFields  collected by the storefront, used only when a new account is created
     * @return array{authorization_url: string, state: string, expires_at: string}
     */
    public function start(string $provider, string $intent, ?Customer $customer = null, array $customFields = []): array
    {
        $this->assertProvider($provider);
        $tenant = self::tenant();
        // The relay page reads which storefront to return to from the state's prefix.
        $storefront = rtrim(strtr(base64_encode(FrontendUrl::storefront($tenant, '/')), '+/', '-_'), '=');
        $state = $storefront.'.'.Str::random(40);
        $expires = now()->addMinutes(self::STATE_TTL_MINUTES);

        Cache::put(self::stateKey($state), [
            'tenant' => (string) $tenant->getTenantKey(),
            'provider' => $provider,
            'intent' => $intent,
            'customer_id' => $customer?->id,
            'custom_fields' => $customFields,
        ], $expires);

        $driver = Socialite::driver($provider)->stateless();

        if ($provider === 'google') {
            $driver->with(['prompt' => 'select_account']);
        }

        $url = $driver->redirect()->getTargetUrl();

        return [
            'authorization_url' => $url.(str_contains($url, '?') ? '&' : '?').'state='.rawurlencode($state),
            'state' => $state,
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * Completes a sign-in (the request carries the provider's code).
     *
     * @return array{token: string, token_type: string, expires_at: string|null, customer: Customer, registered: bool}
     */
    public function login(string $provider, string $state, ?string $guestToken = null, string $device = 'api'): array
    {
        $flow = $this->consumeState($provider, $state, self::LOGIN);
        $social = $this->providerUser($provider);

        // A concurrent callback for the same new identity loses the unique race; the retry finds the winner's rows.
        for ($attempt = 1; ; $attempt++) {
            try {
                [$customer, $registered] = DB::connection('tenant')->transaction(fn (): array => $this->resolve($provider, $social, (array) $flow['custom_fields']));

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }

        if ($registered) {
            if (! $customer->hasVerifiedEmail()) {
                $customer->sendEmailVerificationNotification();
            }

            $this->notifications->dispatch('customer.welcome', $customer, ['customer_name' => $customer->name, 'store_name' => Customer::storeName()]);
        }

        $customer->forceFill(['last_login_at' => now()])->save();
        CustomerAuthenticated::dispatch($customer, $guestToken, $registered);

        return TokenIssuer::issue($customer, 'customer', $device) + ['customer' => $customer, 'registered' => $registered];
    }

    /**
     * Links the provider identity to the signed-in customer (the request
     * carries the provider's code).
     */
    public function link(Customer $customer, string $provider, string $state): CustomerSocialAccount
    {
        $flow = $this->consumeState($provider, $state, self::LINK);

        if ((int) $flow['customer_id'] !== $customer->id) {
            throw ApiException::unprocessable('social_state_invalid', 'This sign-in link has expired. Start again.');
        }

        $social = $this->providerUser($provider);

        return DB::connection('tenant')->transaction(function () use ($customer, $provider, $social): CustomerSocialAccount {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            $existing = CustomerSocialAccount::query()->where('provider', $provider)->where('provider_user_id', self::providerId($social))->first();

            if ($existing !== null) {
                return $existing->customer_id === $customer->id ? $existing
                    : throw ApiException::conflict('social_identity_in_use', 'This '.self::label($provider).' account is already linked to another customer of this store.');
            }

            if (CustomerSocialAccount::query()->where('customer_id', $customer->id)->where('provider', $provider)->exists()) {
                throw ApiException::conflict('social_provider_already_linked', 'Another '.self::label($provider).' account is linked. Unlink it first.');
            }

            $account = $this->attach($customer, $provider, $social);
            ActivityRecorder::tenant('customer_auth', self::label($provider).' linked', $customer, ['provider' => $provider], $customer);

            return $account;
        });
    }

    /**
     * Refused when it would leave the customer with no way to sign in.
     */
    public function unlink(Customer $customer, string $provider): void
    {
        DB::connection('tenant')->transaction(function () use ($customer, $provider): void {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $account = CustomerSocialAccount::query()->where('customer_id', $locked->id)->where('provider', $provider)->first()
                ?? throw ApiException::unprocessable('social_not_linked', self::label($provider).' is not linked to this account.');

            if ($locked->password === null && CustomerSocialAccount::query()->where('customer_id', $locked->id)->whereKeyNot($account->id)->doesntExist()) {
                throw ApiException::unprocessable('last_login_method', 'Set a password before unlinking '.self::label($provider).', or you could not sign in again.');
            }

            $account->delete();
            ActivityRecorder::tenant('customer_auth', self::label($provider).' unlinked', $locked, ['provider' => $provider], $locked);
        });
    }

    /**
     * @return Collection<int, CustomerSocialAccount>
     */
    public function accounts(Customer $customer): Collection
    {
        return CustomerSocialAccount::query()->where('customer_id', $customer->id)->orderBy('provider')->get();
    }

    /**
     * Personal data (§26.4): an erased customer's identities go.
     */
    public function eraseForCustomer(Customer $customer): void
    {
        CustomerSocialAccount::query()->where('customer_id', $customer->id)->delete();
    }

    /**
     * @param  array<string, mixed>  $customFields
     * @return array{0: Customer, 1: bool} the customer and whether it was created
     */
    private function resolve(string $provider, SocialUser $social, array $customFields): array
    {
        $identity = CustomerSocialAccount::query()->where('provider', $provider)->where('provider_user_id', self::providerId($social))->lockForUpdate()->first();

        if ($identity !== null) {
            $customer = Customer::withTrashed()->find($identity->customer_id);
            $this->assertUsable($customer);
            $identity->forceFill(['provider_email' => self::email($social), 'last_used_at' => now()])->save();

            /** @var Customer $customer */
            return [$customer, false];
        }

        $email = self::email($social) ?? throw ApiException::unprocessable('social_email_required',
            self::label($provider).' did not share an email address. Allow email access, or register with your email.');
        $verified = self::emailVerified($provider, $social);
        $existing = Customer::withTrashed()->where('email', $email)->lockForUpdate()->first();

        if ($existing !== null) {
            // Only a provider-verified email proves ownership of an existing account.
            if (! $verified) {
                throw ApiException::conflict('social_email_unverified', 'An account with this email already exists. Sign in with your password, then link '.self::label($provider).' from your account.');
            }

            $this->assertUsable($existing);

            // An unverified account holding a password may belong to someone who never owned the address.
            if ($existing->password !== null && $existing->email_verified_at === null) {
                throw ApiException::conflict('account_email_unverified', 'Verify this account\'s email, or reset its password, before signing in with '.self::label($provider).'.');
            }

            if (CustomerSocialAccount::query()->where('customer_id', $existing->id)->where('provider', $provider)->exists()) {
                throw ApiException::conflict('social_provider_already_linked', 'This account is linked to a different '.self::label($provider).' account.');
            }

            if ($existing->email_verified_at === null) {
                $existing->markEmailAsVerified();
            }

            $this->attach($existing, $provider, $social);
            ActivityRecorder::tenant('customer_auth', self::label($provider).' linked on sign-in', $existing, ['provider' => $provider], $existing);

            return [$existing, false];
        }

        $customer = $this->customers->registerFromSocial(
            ['name' => trim((string) ($social->getName() ?: $social->getNickname() ?: Str::before($email, '@'))), 'email' => $email, 'custom_fields' => $customFields],
            $verified,
        );
        $this->attach($customer, $provider, $social);
        ActivityRecorder::tenant('customer_auth', 'Registered with '.self::label($provider), $customer, ['provider' => $provider], $customer);

        return [$customer, true];
    }

    private function attach(Customer $customer, string $provider, SocialUser $social): CustomerSocialAccount
    {
        $account = new CustomerSocialAccount;
        $account->forceFill([
            'customer_id' => $customer->id,
            'provider' => $provider,
            'provider_user_id' => self::providerId($social),
            'provider_email' => self::email($social),
            'provider_email_verified' => self::emailVerified($provider, $social),
            'last_used_at' => now(),
        ])->save();

        return $account;
    }

    private function assertUsable(?Customer $customer): void
    {
        if ($customer === null || $customer->trashed() || $customer->anonymized_at !== null || ! $customer->is_active) {
            throw ApiException::forbidden('account_disabled', 'This account is disabled.');
        }
    }

    /**
     * @return array{tenant: string, provider: string, intent: string, customer_id: int|null, custom_fields: array<string, mixed>}
     */
    private function consumeState(string $provider, string $state, string $intent): array
    {
        $this->assertProvider($provider);
        // One use only: a replayed or stolen state finds nothing.
        $flow = Cache::pull(self::stateKey($state));

        if (! is_array($flow) || $flow['tenant'] !== (string) self::tenant()->getTenantKey() || $flow['provider'] !== $provider || $flow['intent'] !== $intent) {
            throw ApiException::unprocessable('social_state_invalid', 'This sign-in link has expired. Start again.');
        }

        return $flow;
    }

    private function providerUser(string $provider): SocialUser
    {
        try {
            $user = Socialite::driver($provider)->stateless()->user();
        } catch (Throwable $e) {
            // Never the payload: it may hold the code or a token.
            Log::warning('Social sign-in failed at the provider.', ['provider' => $provider, 'error' => class_basename($e)]);

            throw ApiException::unprocessable('social_auth_failed', self::label($provider).' could not confirm this sign-in. Try again.');
        }

        if (self::providerId($user) === '') {
            throw ApiException::unprocessable('social_auth_failed', self::label($provider).' could not confirm this sign-in. Try again.');
        }

        return $user;
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, $this->providers(), true)) {
            throw ApiException::unprocessable('social_provider_unavailable', 'This sign-in method is not available.');
        }
    }

    private static function providerId(SocialUser $user): string
    {
        return mb_substr(trim((string) $user->getId()), 0, 191);
    }

    private static function email(SocialUser $user): ?string
    {
        $email = strtolower(trim((string) $user->getEmail()));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * Google states whether it verified the address; Facebook does not, so
     * its email never proves ownership of an existing account.
     */
    private static function emailVerified(string $provider, SocialUser $user): bool
    {
        if ($provider !== 'google' || self::email($user) === null) {
            return false;
        }

        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];

        return ($raw['email_verified'] ?? null) === true || ($raw['email_verified'] ?? null) === 'true';
    }

    private static function label(string $provider): string
    {
        return ucfirst($provider);
    }

    private static function stateKey(string $state): string
    {
        return 'customer-social-state:'.hash('sha256', $state);
    }

    private static function tenant(): Tenant
    {
        $tenant = tenant();

        return $tenant instanceof Tenant ? $tenant : throw new \RuntimeException('Social sign-in runs in a tenant.');
    }
}
