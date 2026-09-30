<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use Database\Seeders\Landlord\PlatformAccessSeeder;

it('describes each setting input from its rules: options, min, max and nullable', function (): void {
    $this->seed(PlatformAccessSeeder::class);
    $admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $admin->assignRole('super-admin');
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('t', ['platform'])->plainTextToken];

    $localization = $this->landlordJson('GET', '/api/admin/platform-settings/localization', [], $auth)->assertOk()->json('data');
    expect($localization['default_date_format']['options'])->toBe(['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'DD MMM YYYY', 'MMM DD, YYYY'])
        ->and($localization['default_time_format']['options'])->toBe(['24h', '12h'])
        ->and($localization['default_currency']['options'])->toBeNull();

    $trials = $this->landlordJson('GET', '/api/admin/platform-settings/trials', [], $auth)->json('data');
    expect($trials['default_trial_days'])->toMatchArray(['min' => 0, 'max' => 90, 'nullable' => false, 'reason_required' => true]);

    $domains = $this->landlordJson('GET', '/api/admin/platform-settings/custom_domains', [], $auth)->json('data');
    expect($domains['custom_domain_cname_target'])->toMatchArray(['nullable' => true, 'max' => 253]);
});
