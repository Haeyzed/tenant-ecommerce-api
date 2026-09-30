<?php

declare(strict_types=1);

use App\Modules\Settings\Models\TenantSetting;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
    tenancy()->initialize($this->tenant);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $clerk->assignRole('staff');
    $this->auth = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];
    $this->clerk = ['Authorization' => 'Bearer '.$clerk->createToken('t', ['staff'])->plainTextToken];
});

it('uploads, replaces and removes the store logo, which completes the onboarding store details', function (): void {
    $first = $this->tenantJson('POST', '/api/admin/settings/media', ['setting' => 'store_logo', 'image' => UploadedFile::fake()->image('logo.png', 200, 80)], $this->auth)
        ->assertCreated()->assertJsonPath('data.setting', 'store_logo')->json('data');
    $this->tenantJson('GET', '/api/storefront/config')->assertJsonPath('data.business.logo_url', $first['url']);

    // A new logo replaces the old file.
    $second = $this->tenantJson('POST', '/api/admin/settings/media', ['setting' => 'store_logo', 'image' => UploadedFile::fake()->image('logo2.png', 200, 80)], $this->auth)
        ->assertCreated()->json('data');
    tenancy()->initialize($this->tenant);
    expect(Media::query()->whereKey($first['media_id'])->exists())->toBeFalse()
        ->and(app(TenantSettingsService::class)->get('store_logo_media_id'))->toBe($second['media_id'])
        ->and(Media::query()->where('model_type', 'tenant_setting')->count())->toBe(1);

    app(TenantSettingsService::class)->set('store_contact_phone', '+2348000000000');
    $steps = collect($this->tenantJson('GET', '/api/admin/onboarding', [], $this->auth)->assertOk()->json('data.steps'))->pluck('complete', 'key');
    expect($steps['store_details'])->toBeTrue();

    $this->tenantJson('DELETE', '/api/admin/settings/media/store_logo', [], $this->auth)->assertOk();
    $this->tenantJson('GET', '/api/storefront/config')->assertJsonPath('data.business.logo_url', null);
    tenancy()->initialize($this->tenant);
    expect(Media::query()->where('model_type', 'tenant_setting')->count())->toBe(0)
        ->and(TenantSetting::query()->where('key', 'store_logo_media_id')->value('value'))->toBeNull();
});

it('stores the favicon and the storefront share image, and refuses bad input and missing permission', function (): void {
    $favicon = $this->tenantJson('POST', '/api/admin/settings/media', ['setting' => 'favicon', 'image' => UploadedFile::fake()->image('f.png', 64, 64)], $this->auth)->assertCreated()->json('data');
    $share = $this->tenantJson('POST', '/api/admin/settings/media', ['setting' => 'seo_share_image', 'image' => UploadedFile::fake()->image('s.jpg', 1200, 630)], $this->auth)->assertCreated()->json('data');

    $this->tenantJson('GET', '/api/storefront/config')->assertJsonPath('data.business.favicon_url', $favicon['url'])
        ->assertJsonPath('data.storefront.seo_share_image_media_id', $share['media_id']);

    $this->tenantJson('POST', '/api/admin/settings/media', ['setting' => 'store_banner', 'image' => UploadedFile::fake()->image('x.png')], $this->auth)->assertStatus(422);
    $this->tenantJson('POST', '/api/admin/settings/media', ['setting' => 'store_logo', 'image' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], $this->auth)->assertStatus(422);
    $this->tenantJson('POST', '/api/admin/settings/media', ['setting' => 'store_logo', 'image' => UploadedFile::fake()->image('x.png')], $this->clerk)->assertForbidden();
});

it('uploads and removes a variant image within its own product only', function (): void {
    $size = $this->tenantJson('POST', '/api/admin/product-options', ['name' => 'Size', 'values' => ['S']], $this->auth)->assertCreated()->json('data');
    $hoodie = $this->tenantJson('POST', '/api/admin/products', ['name' => 'Hoodie', 'price' => '30', 'product_type' => 'variable'], $this->auth)->assertCreated()->json('data');
    $other = $this->tenantJson('POST', '/api/admin/products', ['name' => 'Mug', 'price' => '5'], $this->auth)->assertCreated()->json('data');
    $variant = $this->tenantJson('POST', "/api/admin/products/{$hoodie['id']}/variants", ['sku' => 'HD-S', 'option_value_ids' => [$size['values'][0]['id']]], $this->auth)
        ->assertCreated()->json('data');

    $url = $this->tenantJson('POST', "/api/admin/products/{$hoodie['id']}/variants/{$variant['id']}/image", ['image' => UploadedFile::fake()->image('v.jpg')], $this->auth)
        ->assertCreated()->json('data.url');
    $this->tenantJson('GET', "/api/admin/products/{$hoodie['id']}/variants", [], $this->auth)->assertJsonPath('data.0.image_url', $url);
    $this->tenantJson('POST', "/api/admin/products/{$other['id']}/variants/{$variant['id']}/image", ['image' => UploadedFile::fake()->image('v.jpg')], $this->auth)->assertNotFound();

    $this->tenantJson('DELETE', "/api/admin/products/{$hoodie['id']}/variants/{$variant['id']}/image", [], $this->auth)->assertOk();
    $this->tenantJson('GET', "/api/admin/products/{$hoodie['id']}/variants", [], $this->auth)->assertJsonPath('data.0.image_url', null);
});
