<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Customers\Models\Customer;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
    tenancy()->initialize($this->tenant);
    $clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $clerk->assignRole('staff');
    $this->clerk = ['Authorization' => 'Bearer '.$clerk->createToken('t', ['staff'])->plainTextToken];
});

it('computes the onboarding checklist from the store data for any staff user', function (): void {
    $keys = fn (): array => collect($this->tenantJson('GET', '/api/admin/onboarding', [], $this->clerk)->assertOk()->json('data.steps'))->pluck('complete', 'key')->all();

    expect($keys())->toMatchArray(['first_product' => false, 'tax' => false, 'shipping' => true, 'policies' => false, 'custom_domain' => false]);

    tenancy()->initialize($this->tenant);
    Product::query()->create(['name' => 'Runner', 'price' => '10', 'is_active' => true]);
    app(TenantSettingsService::class)->set('tax_setup_confirmed', true);
    CmsPage::query()->whereIn('system_key', ['privacy_policy', 'terms', 'refund_policy'])->update(['status' => CmsPage::PUBLISHED]);

    // A physical product now needs a shipping method.
    expect($keys())->toMatchArray(['first_product' => true, 'tax' => true, 'shipping' => false])
        ->and($this->tenantJson('GET', '/api/admin/onboarding', [], $this->clerk)->json('data.total'))->toBe(6);
});

it('gives customers their own inbox and notification preferences', function (): void {
    $ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'Secret123']);
    $bola = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@example.test', 'password' => 'Secret123']);
    $auth = ['Authorization' => 'Bearer '.$ada->createToken('t', ['customer'])->plainTextToken];
    $mine = DatabaseNotification::query()->create(['id' => (string) Str::uuid(), 'type' => 'order.confirmed', 'notifiable_type' => $ada->getMorphClass(),
        'notifiable_id' => $ada->id, 'data' => ['subject' => 'Order confirmed', 'body' => 'Thanks']]);
    $theirs = DatabaseNotification::query()->create(['id' => (string) Str::uuid(), 'type' => 'order.confirmed', 'notifiable_type' => $bola->getMorphClass(),
        'notifiable_id' => $bola->id, 'data' => ['subject' => 'Other', 'body' => 'x']]);

    $this->tenantJson('GET', '/api/notifications', [], $auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('meta.unread_count', 1);
    $this->tenantJson('POST', "/api/notifications/{$theirs->id}/read", [], $auth)->assertNotFound();
    $this->tenantJson('POST', "/api/notifications/{$mine->id}/read", [], $auth)->assertOk();

    // Mandatory messages cannot be switched off; optional ones can.
    $this->tenantJson('PATCH', '/api/notification-preferences', ['template_key' => 'order.confirmed', 'channel' => 'email', 'enabled' => false], $auth)->assertStatus(422);
    $this->tenantJson('PATCH', '/api/notification-preferences', ['template_key' => 'review.approved', 'channel' => 'email', 'enabled' => false], $auth)
        ->assertOk()->assertJsonPath('data.0.enabled', false);
    $this->tenantJson('POST', '/api/notification-preferences/reset', [], $auth)->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('GET', '/api/notifications')->assertUnauthorized();
});
