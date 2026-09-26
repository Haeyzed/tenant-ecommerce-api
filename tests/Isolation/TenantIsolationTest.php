<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->a = $this->createTenant('a');
    $this->b = $this->createTenant('b');
});

it('connects each tenant to its own database', function (): void {
    tenancy()->initialize($this->a);
    $databaseA = DB::connection('tenant')->getDatabaseName();
    User::query()->create(['name' => 'Only A', 'email' => 'only@a.test', 'password' => 'secret123', 'is_active' => true]);

    tenancy()->initialize($this->b);

    expect(DB::connection('tenant')->getDatabaseName())->not->toBe($databaseA)
        ->and(User::query()->where('email', 'only@a.test')->exists())->toBeFalse();
});

it('keeps the default cache separate per tenant', function (): void {
    tenancy()->initialize($this->a);
    Cache::put('probe', 'tenant-a');

    tenancy()->initialize($this->b);
    expect(Cache::get('probe'))->toBeNull();

    tenancy()->end();
    expect(Cache::get('probe'))->toBeNull();
});

it('keeps tenant settings separate per tenant', function (): void {
    tenancy()->initialize($this->a);
    app(TenantSettingsService::class)->set('store_name', 'Shop A');

    expect(app(TenantSettingsService::class)->get('store_name'))->toBe('Shop A');

    // Neither A's row nor A's cached settings may reach B. (Switching back
    // to A is not asserted: the harness rolls back A's test transaction
    // when its connection is purged.)
    tenancy()->initialize($this->b);
    expect(app(TenantSettingsService::class)->get('store_name'))->toBe('Tenant B');
});

it('keeps module states separate per tenant', function (): void {
    $this->subscribe($this->a, 'standard');
    $this->subscribe($this->b, 'basic');
    $features = app(FeatureAccessService::class);

    expect($features->state($this->a, 'pos'))->toBe(ModuleState::Available)
        ->and($features->state($this->b, 'pos'))->toBe(ModuleState::Unavailable);
});

it('keeps notification template customisations separate per tenant', function (): void {
    tenancy()->initialize($this->a);
    app(NotificationTemplateService::class)->updateTemplate('order.confirmed', ['body' => 'A only']);

    tenancy()->initialize($this->b);
    expect(NotificationTemplate::forScope(NotificationScope::Tenant)->where('key', 'order.confirmed')->value('body'))
        ->not->toBe('A only');
});
