<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    $this->seed(PlatformAccessSeeder::class);
    $admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $admin->assignRole('super-admin');
    $this->token = ['Authorization' => 'Bearer '.$admin->createToken('t', ['platform'])->plainTextToken];
});

it('uploads and removes the platform logo, exposed with its URL in the public config', function (): void {
    $logo = $this->landlordJson('POST', '/api/admin/platform-settings/media', ['setting' => 'platform_logo', 'image' => UploadedFile::fake()->image('logo.png', 200, 80)], $this->token)
        ->assertCreated()->json('data');

    $this->landlordJson('GET', '/api/platform/config')->assertOk()
        ->assertJsonPath('data.platform_logo_media_id', $logo['media_id'])
        ->assertJsonPath('data.platform_logo_url', $logo['url']);

    $this->landlordJson('DELETE', '/api/admin/platform-settings/media/platform_logo', [], $this->token)->assertOk();
    $this->landlordJson('GET', '/api/platform/config')->assertJsonPath('data.platform_logo_url', null);

    $this->landlordJson('POST', '/api/admin/platform-settings/media', ['setting' => 'store_logo', 'image' => UploadedFile::fake()->image('x.png')], $this->token)->assertStatus(422);

    $clerk = PlatformUser::query()->create(['name' => 'Clerk', 'email' => 'clerk@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->landlordJson('POST', '/api/admin/platform-settings/media', ['setting' => 'platform_logo', 'image' => UploadedFile::fake()->image('x.png')],
        ['Authorization' => 'Bearer '.$clerk->createToken('t', ['platform'])->plainTextToken])->assertForbidden();
});
