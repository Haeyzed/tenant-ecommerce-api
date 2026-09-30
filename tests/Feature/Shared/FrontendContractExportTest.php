<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->dir = 'storage/framework/testing/frontend-contract';
    File::deleteDirectory(base_path($this->dir));
});

afterEach(function (): void {
    File::deleteDirectory(base_path($this->dir));
});

function contractFile(string $dir, string $file): array
{
    return json_decode((string) file_get_contents(base_path("{$dir}/{$file}")), true, flags: JSON_THROW_ON_ERROR);
}

it('writes a route manifest whose gates match the registries', function (): void {
    $this->artisan('frontend:contract', ['--path' => $this->dir])->assertSuccessful();

    foreach (['routes.landlord.json', 'routes.tenant.json', 'modules.json', 'limits.json', 'permissions.landlord.json', 'permissions.tenant.json', 'error-codes.json', 'storefront.json'] as $file) {
        expect(File::exists(base_path("{$this->dir}/{$file}")))->toBeTrue($file);
    }

    $tenant = collect(contractFile($this->dir, 'routes.tenant.json'))->keyBy('name');
    $landlord = collect(contractFile($this->dir, 'routes.landlord.json'))->keyBy('name');

    expect($tenant['tenant.settings.media.store'])->toMatchArray([
        'methods' => ['POST'], 'uri' => '/api/admin/settings/media', 'group' => 'tenant.admin', 'actor' => 'staff',
        'permission' => 'settings.media.create', 'usage_limit' => 'max_storage_mb', 'module' => null,
    ])
        ->and($tenant['tenant.admin.hr.shifts.store'])->toMatchArray(['module' => 'hr', 'permission' => 'hr.shifts.create'])
        ->and($landlord->has('tenant.settings.media.store'))->toBeFalse()
        ->and($landlord['landlord.settings.media.store']['permission'] ?? null)->toBe('platform-settings.media.create');

    // Every gate names something the registries define.
    $modules = collect(contractFile($this->dir, 'modules.json'))->pluck('key');
    $limits = collect(contractFile($this->dir, 'limits.json'))->pluck('key');

    foreach (['tenant' => $tenant, 'landlord' => $landlord] as $context => $routes) {
        $permissions = contractFile($this->dir, "permissions.{$context}.json")['permissions'];
        expect($permissions)->toBe(config("permissions.generated.{$context}"));

        foreach ($routes as $route) {
            if ($route['permission'] !== null) {
                expect($permissions)->toContain($route['permission']);
            }
            if ($route['module'] !== null) {
                expect($modules)->toContain($route['module']);
            }
            if ($route['usage_limit'] !== null) {
                expect($limits)->toContain($route['usage_limit']);
            }
        }
    }

    expect($tenant->where('idempotency', true))->not->toBeEmpty()
        ->and($tenant->where('wind_down', true))->not->toBeEmpty()
        ->and(contractFile($this->dir, 'storefront.json'))->toBe(['themes' => config('storefront.themes'), 'fonts' => config('storefront.fonts')]);
});

it('lists error codes with their statuses and owning modules', function (): void {
    $this->artisan('frontend:contract', ['--path' => $this->dir])->assertSuccessful();

    $codes = collect(contractFile($this->dir, 'error-codes.json'))->keyBy('code');

    expect($codes['unknown_image_setting'])->toBe(['code' => 'unknown_image_setting', 'statuses' => [422], 'modules' => ['Settings']])
        ->and($codes['cart_not_found']['statuses'])->toBe([404])
        ->and($codes['module_locked']['statuses'])->toBe([403])
        ->and($codes['validation_failed']['statuses'])->toBe([422])
        ->and($codes->keys()->all())->toBe($codes->keys()->sort()->values()->all());
});
