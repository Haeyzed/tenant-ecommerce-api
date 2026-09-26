<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Auth\Support\EmailVerificationLink;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(PlatformAccessSeeder::class);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);

    $this->admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->admin->assignRole('super-admin');
});

function platformLogin(string $email = 'root@platform.test', string $password = 'Secret123'): TestResponse
{
    return test()->landlordJson('POST', '/api/admin/auth/login', ['email' => $email, 'password' => $password]);
}

it('logs in and returns a platform token usable on /me', function (): void {
    $response = platformLogin()->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'root@platform.test')
        ->assertJsonPath('data.token_type', 'Bearer');

    $token = $response->json('data.token');
    expect($token)->toMatch('/^\d+\|tea_\w+$/');

    $this->landlordJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.roles', ['super-admin'])
        ->assertJsonPath('data.display.date_format', 'YYYY-MM-DD');
});

it('rejects wrong credentials without disclosing which part was wrong', function (): void {
    platformLogin('root@platform.test', 'wrong-password')->assertStatus(422)->assertJsonPath('meta.error_code', 'validation_failed');
    platformLogin('nobody@platform.test', 'Secret123')->assertStatus(422)->assertJsonPath('errors.email.0', __('auth.failed'));
});

it('refuses disabled accounts', function (): void {
    $this->admin->forceFill(['is_active' => false])->save();

    platformLogin()->assertForbidden()->assertJsonPath('meta.error_code', 'account_disabled');
});

it('rejects requests without a valid token', function (): void {
    $this->landlordJson('GET', '/api/admin/auth/me')->assertUnauthorized()->assertJsonPath('meta.error_code', 'unauthenticated');
    $this->landlordJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer tea_invalid'])->assertUnauthorized();
});

it('rejects a token that carries another actor ability', function (): void {
    $token = $this->admin->createToken('x', ['affiliate'])->plainTextToken;

    $this->landlordJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('revokes the current token on logout', function (): void {
    $token = platformLogin()->json('data.token');

    $this->landlordJson('POST', '/api/admin/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();

    app('auth')->forgetGuards();
    $this->landlordJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('sends a reset link through the template and resets the password', function (): void {
    $this->landlordJson('POST', '/api/admin/auth/password/forgot', ['email' => 'root@platform.test'])->assertStatus(202);
    $this->landlordJson('POST', '/api/admin/auth/password/forgot', ['email' => 'nobody@platform.test'])->assertStatus(202);

    $url = null;
    Notification::assertSentTo($this->admin, TemplatedNotification::class, function (TemplatedNotification $n) use (&$url): bool {
        preg_match('/(https?:\S+reset-password\S+)/', $n->body, $m);
        $url = $m[1] ?? null;

        return $n->key === 'platform_user.password_reset';
    });

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    $this->landlordJson('POST', '/api/admin/auth/password/reset', [
        'token' => $query['token'],
        'email' => 'root@platform.test',
        'password' => 'NewSecret456',
        'password_confirmation' => 'NewSecret456',
    ])->assertOk();

    platformLogin('root@platform.test', 'NewSecret456')->assertOk();
});

it('verifies an email with a signed link and rejects a tampered one', function (): void {
    $this->admin->forceFill(['email_verified_at' => null])->save();
    $params = EmailVerificationLink::parameters('platform', $this->admin->id, $this->admin->email);

    $this->landlordJson('POST', '/api/admin/auth/email/verify', array_merge($params, ['expires' => $params['expires'] + 1]))
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'verification_link_invalid');

    $this->landlordJson('POST', '/api/admin/auth/email/verify', $params)->assertOk();
    expect($this->admin->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('stores display preferences and falls back to the platform default', function (): void {
    $token = platformLogin()->json('data.token');
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->landlordJson('PATCH', '/api/admin/auth/preferences', ['date_format' => 'DD/MM/YYYY'], $headers)
        ->assertOk()
        ->assertJsonPath('data.preferences.date_format', 'DD/MM/YYYY');

    $this->landlordJson('GET', '/api/admin/auth/me', [], $headers)
        ->assertJsonPath('data.display.date_format', 'DD/MM/YYYY')
        ->assertJsonPath('data.display.time_format', '24h');

    $this->landlordJson('PATCH', '/api/admin/auth/preferences', ['date_format' => 'nonsense'], $headers)->assertStatus(422);
});
