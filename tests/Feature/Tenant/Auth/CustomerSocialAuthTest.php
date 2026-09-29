<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSocialAccount;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    Notification::fake();
    config([
        'services.google' => ['client_id' => 'g-id', 'client_secret' => 'g-secret', 'redirect' => 'https://auth.platform.test/oauth/google'],
        'services.facebook' => ['client_id' => 'f-id', 'client_secret' => 'f-secret', 'redirect' => 'https://auth.platform.test/oauth/facebook'],
    ]);
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
});

/**
 * The provider's answer for the next callback.
 *
 * @param  array<string, mixed>  $raw
 */
function socialUser(string $provider, string $id, ?string $email, array $raw = []): void
{
    Socialite::fake($provider, (new SocialiteUser)->map(['id' => $id, 'name' => 'Ada Obi', 'email' => $email])->setRaw($raw));
}

/**
 * Starts a flow and completes it, as the storefront would.
 *
 * @param  array<string, mixed>  $start
 */
function socialSignIn(string $provider, string $key = 'a', array $start = []): TestResponse
{
    $state = test()->tenantJson('POST', "/api/auth/social/{$provider}/redirect", $start, [], $key)->assertOk()->json('data.state');

    return test()->tenantJson('POST', "/api/auth/social/{$provider}/callback", ['code' => 'provider-code', 'state' => $state], [], $key);
}

it('signs up, signs in and links by verified email without ever duplicating or taking over an account', function (): void {
    $this->tenantJson('GET', '/api/auth/social/providers')->assertOk()->assertJsonPath('data.providers', ['google', 'facebook']);
    $start = $this->tenantJson('POST', '/api/auth/social/google/redirect')->assertOk()->json('data');
    expect($start['authorization_url'])->toContain('state='.rawurlencode($start['state']))
        ->and(base64_decode(strtr(explode('.', $start['state'])[0], '-_', '+/')))->toContain('tenant-a');

    // New Google identity with a verified email: a new, verified account.
    socialUser('google', 'g-1', 'Ada@Example.test', ['email_verified' => true]);
    $first = socialSignIn('google')->assertCreated()->assertJsonPath('data.registered', true)->assertJsonPath('data.customer.email_verified', true)->json('data');
    expect($first['token'])->toBeString();

    // The same identity signs the same customer in.
    socialSignIn('google')->assertOk()->assertJsonPath('data.registered', false)->assertJsonPath('data.customer.id', $first['customer']['id']);
    tenancy()->initialize($this->tenant);
    expect(Customer::query()->count())->toBe(1)->and(CustomerSocialAccount::query()->count())->toBe(1)
        // No provider token is kept anywhere.
        ->and(Schema::connection('tenant')->getColumnListing('customer_social_accounts'))->not->toContain('token', 'access_token', 'refresh_token');

    // An existing, verified password account is linked, and keeps its password.
    tenancy()->initialize($this->tenant);
    $bola = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@example.test', 'password' => 'Secret123']);
    $bola->markEmailAsVerified();
    socialUser('google', 'g-2', 'bola@example.test', ['email_verified' => true]);
    socialSignIn('google')->assertOk()->assertJsonPath('data.customer.id', $bola->id);
    tenancy()->initialize($this->tenant);
    expect(Hash::check('Secret123', (string) $bola->fresh()->password))->toBeTrue();

    // An unverified password account is never taken over, even by a verified Google email.
    $squat = Customer::query()->create(['name' => 'Squatter', 'email' => 'chidi@example.test', 'password' => 'Secret123']);
    socialUser('google', 'g-3', 'chidi@example.test', ['email_verified' => true]);
    socialSignIn('google')->assertStatus(409)->assertJsonPath('meta.error_code', 'account_email_unverified');

    // Google without a verified flag, or Facebook (which never vouches), cannot claim an existing email.
    socialUser('google', 'g-4', 'bola@example.test', ['email_verified' => false]);
    socialSignIn('google')->assertStatus(409)->assertJsonPath('meta.error_code', 'social_email_unverified');
    socialUser('facebook', 'f-1', 'bola@example.test');
    socialSignIn('facebook')->assertStatus(409)->assertJsonPath('meta.error_code', 'social_email_unverified');

    // A new Facebook email makes an unverified account, which is sent a verification email.
    socialUser('facebook', 'f-2', 'dayo@example.test');
    socialSignIn('facebook')->assertCreated()->assertJsonPath('data.customer.email_verified', false);

    // No email: nothing is created.
    socialUser('facebook', 'f-3', null);
    socialSignIn('facebook')->assertStatus(422)->assertJsonPath('meta.error_code', 'social_email_required');

    // A disabled account stays disabled.
    tenancy()->initialize($this->tenant);
    $bola->forceFill(['is_active' => false])->save();
    socialUser('google', 'g-2', 'bola@example.test', ['email_verified' => true]);
    socialSignIn('google')->assertForbidden()->assertJsonPath('meta.error_code', 'account_disabled');

    tenancy()->initialize($this->tenant);
    expect(Customer::query()->count())->toBe(4)->and($squat->fresh()->socialAccounts()->count())->toBe(0);
});

it('rejects forged, replayed and cross-tenant states, provider failures and unavailable providers', function (): void {
    $this->subscribe($this->createTenant('b'), 'basic');
    socialUser('google', 'g-1', 'ada@example.test', ['email_verified' => true]);

    $this->tenantJson('POST', '/api/auth/social/google/callback', ['code' => 'c', 'state' => 'forged.state'])->assertStatus(422)->assertJsonPath('meta.error_code', 'social_state_invalid');

    // A state is used once, for its own provider, in its own store.
    $state = $this->tenantJson('POST', '/api/auth/social/google/redirect')->json('data.state');
    $this->tenantJson('POST', '/api/auth/social/google/callback', ['code' => 'c', 'state' => $state], [], 'b')->assertStatus(422);
    $this->tenantJson('POST', '/api/auth/social/facebook/callback', ['code' => 'c', 'state' => $state])->assertStatus(422);
    $state = $this->tenantJson('POST', '/api/auth/social/google/redirect')->json('data.state');
    $this->tenantJson('POST', '/api/auth/social/google/callback', ['code' => 'c', 'state' => $state])->assertCreated();
    $this->tenantJson('POST', '/api/auth/social/google/callback', ['code' => 'c', 'state' => $state])->assertStatus(422)->assertJsonPath('meta.error_code', 'social_state_invalid');

    // The same Google identity in another store is another customer of that store.
    socialSignIn('google', 'b')->assertCreated();
    expect(tenancy()->find('test-tenant-b')->run(static fn (): int => Customer::query()->count()))->toBe(1);

    Socialite::fake('google', static fn () => throw new RuntimeException('invalid_grant'));
    socialSignIn('google')->assertStatus(422)->assertJsonPath('meta.error_code', 'social_auth_failed');

    config(['services.facebook.client_id' => null]);
    $this->tenantJson('GET', '/api/auth/social/providers')->assertJsonPath('data.providers', ['google']);
    $this->tenantJson('POST', '/api/auth/social/facebook/redirect')->assertStatus(422)->assertJsonPath('meta.error_code', 'social_provider_unavailable');
    $this->tenantJson('POST', '/api/auth/social/twitter/redirect')->assertNotFound();
});

it('links and unlinks from the account, never leaving the customer without a way in', function (): void {
    socialUser('google', 'g-1', 'ada@example.test', ['email_verified' => true]);
    $auth = ['Authorization' => 'Bearer '.socialSignIn('google')->json('data.token')];

    $this->tenantJson('GET', '/api/account/social-accounts', [], $auth)->assertOk()->assertJsonPath('data.has_password', false)->assertJsonPath('data.linked.0.provider', 'google');
    $this->tenantJson('DELETE', '/api/account/social-accounts/google', [], $auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'last_login_method');

    // Facebook is linked to the signed-in customer whatever its email says.
    socialUser('facebook', 'f-1', 'other@example.test');
    $state = $this->tenantJson('POST', '/api/account/social-accounts/facebook/redirect', [], $auth)->assertOk()->json('data.state');
    $this->tenantJson('POST', '/api/auth/social/facebook/callback', ['code' => 'c', 'state' => $state])->assertStatus(422);
    $state = $this->tenantJson('POST', '/api/account/social-accounts/facebook/redirect', [], $auth)->json('data.state');
    $this->tenantJson('POST', '/api/account/social-accounts/facebook/callback', ['code' => 'c', 'state' => $state], $auth)->assertOk()->assertJsonPath('data.provider', 'facebook');

    // With two methods one can go; a password then allows the last to go too.
    $this->tenantJson('DELETE', '/api/account/social-accounts/facebook', [], $auth)->assertOk();
    $this->tenantJson('POST', '/api/auth/password/set', ['password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'], $auth)->assertOk();
    $this->tenantJson('POST', '/api/auth/password/set', ['password' => 'Other123', 'password_confirmation' => 'Other123'], $auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'password_already_set');
    $this->tenantJson('DELETE', '/api/account/social-accounts/google', [], $auth)->assertOk();
    $this->tenantJson('POST', '/api/auth/login', ['email' => 'ada@example.test', 'password' => 'NewSecret123'])->assertOk();

    // An identity already linked to someone else cannot be linked again.
    tenancy()->initialize($this->tenant);
    $other = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@example.test', 'password' => 'Secret123']);
    $other->socialAccounts()->forceCreate(['provider' => 'google', 'provider_user_id' => 'g-9']);
    socialUser('google', 'g-9', 'bola@example.test', ['email_verified' => true]);
    $state = $this->tenantJson('POST', '/api/account/social-accounts/google/redirect', [], $auth)->json('data.state');
    $this->tenantJson('POST', '/api/account/social-accounts/google/callback', ['code' => 'c', 'state' => $state], $auth)->assertStatus(409)->assertJsonPath('meta.error_code', 'social_identity_in_use');
});
