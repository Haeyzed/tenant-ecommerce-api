<?php

declare(strict_types=1);

use App\Modules\Auth\Services\Tenant\DriverAuthService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Messaging\Models\SmsGatewaySetting;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Services\ShippingService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    $landlord = DB::connection('landlord');
    $landlord->table('countries')->insert([
        ['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-'],
        ['id' => 2, 'iso2' => 'GH', 'iso3' => 'GHA', 'name' => 'Ghana', 'phone_code' => '233', 'native' => 'Ghana', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '8', 'longitude' => '-1', 'emoji' => '-', 'emojiU' => '-'],
    ]);
    $landlord->table('states')->insert([
        ['id' => 10, 'country_id' => 1, 'name' => 'Lagos', 'country_code' => 'NG', 'state_code' => 'LA'],
        ['id' => 11, 'country_id' => 1, 'name' => 'Abuja', 'country_code' => 'NG', 'state_code' => 'FC'],
        ['id' => 20, 'country_id' => 2, 'name' => 'Accra', 'country_code' => 'GH', 'state_code' => 'AA'],
    ]);
});

it('manages tax rates, one per region and class', function (): void {
    $id = $this->tenantJson('POST', '/api/admin/tax-rates', ['name' => 'VAT', 'country_id' => 1, 'rate_percentage' => 7.5], $this->auth)
        ->assertCreated()->assertJsonPath('data.tax_class', 'standard')->assertJsonPath('data.rate_percentage', '7.5000')->json('data.id');

    $this->tenantJson('POST', '/api/admin/tax-rates', ['name' => 'VAT again', 'country_id' => 1, 'rate_percentage' => 5], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('tax_class');
    $this->tenantJson('POST', '/api/admin/tax-rates', ['name' => 'Accra', 'country_id' => 1, 'state_id' => 20, 'rate_percentage' => 5], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('state_id');
    $this->tenantJson('POST', '/api/admin/tax-rates', ['name' => 'Lagos VAT', 'country_id' => 1, 'state_id' => 10, 'rate_percentage' => 10], $this->auth)->assertCreated();
    $this->tenantJson('POST', '/api/admin/tax-rates', ['name' => 'Too high', 'country_id' => 2, 'rate_percentage' => 101], $this->auth)->assertStatus(422);

    $this->tenantJson('PATCH', "/api/admin/tax-rates/{$id}", ['rate_percentage' => 8], $this->auth)->assertOk()->assertJsonPath('data.rate_percentage', '8.0000');
    $this->tenantJson('GET', '/api/admin/tax-rates?country_id=1', [], $this->auth)->assertOk()->assertJsonCount(2, 'data');
    $this->tenantJson('DELETE', "/api/admin/tax-rates/{$id}", [], $this->auth)->assertOk();
});

it('calculates line and shipping tax by address, class and pricing mode', function (): void {
    $tax = app(TaxService::class);
    $settings = app(TenantSettingsService::class);
    $tax->createTaxRate(['name' => 'VAT', 'country_id' => 1, 'rate_percentage' => '7.5']);
    $tax->createTaxRate(['name' => 'Lagos', 'country_id' => 1, 'state_id' => 10, 'rate_percentage' => '10']);
    $tax->createTaxRate(['name' => 'Reduced', 'country_id' => 1, 'tax_class' => 'reduced', 'rate_percentage' => '5']);

    $lines = [
        ['amount' => '100.0000', 'tax_class' => 'standard'],
        ['amount' => '33.3300', 'tax_class' => 'standard'],
        ['amount' => '50.0000', 'tax_class' => 'reduced'],
        ['amount' => '80.0000', 'tax_class' => 'exempt'],
    ];

    // Abuja has no state rate: the country rate applies; 33.33 × 7.5% = 2.49975 rounds to 2.50.
    $abuja = $tax->calculateForLines($lines, ['country_id' => 1, 'state_id' => 11], null, '20', 'NGN');
    expect(array_column($abuja['lines'], 'tax_amount'))->toBe(['7.5000', '2.5000', '2.5000', '0.0000'])
        ->and(array_column($abuja['lines'], 'tax_rate_applied'))->toBe(['7.5000', '7.5000', '5.0000', '0.0000'])
        ->and($abuja['shipping_tax_amount'])->toBe('0.0000')
        ->and($abuja['total'])->toBe('12.5000');

    // Lagos overrides the standard class only; reduced falls back to the country rate.
    $lagos = $tax->calculateForLines($lines, ['country_id' => 1, 'state_id' => 10], null, '0', 'NGN');
    expect(array_column($lagos['lines'], 'tax_amount'))->toBe(['10.0000', '3.3300', '2.5000', '0.0000']);

    $settings->set('prices_include_tax', true);
    $settings->set('tax_shipping', true);
    $inclusive = $tax->calculateForLines([['amount' => '110.0000', 'tax_class' => 'standard']], ['country_id' => 1, 'state_id' => 10], null, '22', 'NGN');
    expect($inclusive['lines'][0]['tax_amount'])->toBe('10.0000')->and($inclusive['shipping_tax_amount'])->toBe('2.0000');

    expect($tax->calculateForLines($lines, ['country_id' => 2], null, '10', 'NGN')['total'])->toBe('0.0000')
        ->and($tax->getTaxRateForAddress(['country_id' => 1, 'state_id' => 10], 'exempt'))->toBeNull();

    // India GST: intra-state splits CGST/SGST; otherwise IGST.
    $settings->set('prices_include_tax', false);
    $settings->set('india_gst_enabled', true);
    $origin = new Warehouse(['name' => 'W', 'state_id' => 10]);
    $intra = $tax->calculateForLines([['amount' => '100.0100', 'tax_class' => 'standard']], ['country_id' => 1, 'state_id' => 10], $origin, '0', 'NGN');
    $inter = $tax->calculateForLines([['amount' => '100.0000', 'tax_class' => 'standard']], ['country_id' => 1, 'state_id' => 11], $origin, '0', 'NGN');
    expect($intra['lines'][0]['tax_breakdown'])->toBe(['cgst' => '5.0000', 'sgst' => '5.0000'])
        ->and($inter['lines'][0]['tax_breakdown'])->toBe(['igst' => '7.5000']);
});

it('resolves shipping zones by the most specific region and offers their active methods', function (): void {
    $nigeria = $this->tenantJson('POST', '/api/admin/shipping-zones', ['name' => 'Nigeria', 'regions' => [['country_id' => 1]]], $this->auth)->assertCreated()->json('data.id');
    $lagos = $this->tenantJson('POST', '/api/admin/shipping-zones', ['name' => 'Lagos', 'regions' => [['country_id' => 1, 'state_id' => 10]]], $this->auth)->assertCreated()->json('data.id');
    $this->tenantJson('POST', '/api/admin/shipping-zones', ['name' => 'Bad', 'regions' => [['country_id' => 1, 'state_id' => 20]]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('regions.0.state_id');
    $this->tenantJson('POST', '/api/admin/shipping-zones', ['name' => 'Dup', 'regions' => [['country_id' => 2], ['country_id' => 2]]], $this->auth)->assertStatus(422);

    $this->tenantJson('POST', '/api/admin/shipping-methods', ['shipping_zone_id' => $nigeria, 'name' => 'GIG', 'fulfillment_type' => 'courier', 'courier_provider' => 'GIG', 'cost' => '2500', 'estimated_days' => 3], $this->auth)->assertCreated();
    $express = $this->tenantJson('POST', '/api/admin/shipping-methods', ['shipping_zone_id' => $lagos, 'name' => 'Same day', 'fulfillment_type' => 'in_house', 'cost' => '1500'], $this->auth)->assertCreated()->json('data.id');
    $this->tenantJson('POST', '/api/admin/shipping-methods', ['shipping_zone_id' => $lagos, 'name' => 'Bike', 'fulfillment_type' => 'in_house', 'courier_provider' => 'DHL', 'cost' => '1'], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('courier_provider');
    $this->tenantJson('POST', '/api/admin/shipping-methods', ['shipping_zone_id' => $lagos, 'name' => 'Off', 'fulfillment_type' => 'courier', 'cost' => '900', 'is_active' => false], $this->auth)->assertCreated();

    $this->tenantJson('GET', '/api/shipping/methods?country_id=1&state_id=10')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Same day')->assertJsonMissingPath('data.0.is_active');
    $this->tenantJson('GET', '/api/shipping/methods?country_id=1&state_id=11')->assertOk()->assertJsonPath('data.0.name', 'GIG')->assertJsonPath('data.0.cost', '2500.0000');
    $this->tenantJson('GET', '/api/shipping/methods?country_id=2')->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('GET', '/api/shipping/methods?address_id=1')->assertNotFound();

    // Zones with methods are deactivated, not deleted.
    $this->tenantJson('DELETE', "/api/admin/shipping-zones/{$lagos}", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'zone_has_methods');
    $this->tenantJson('PATCH', "/api/admin/shipping-zones/{$lagos}", ['is_active' => false], $this->auth)->assertOk();
    $this->tenantJson('GET', '/api/shipping/methods?country_id=1&state_id=10')->assertOk()->assertJsonPath('data.0.name', 'GIG');

    tenancy()->initialize($this->tenant);
    $method = ShippingMethod::query()->findOrFail($express);
    $ebook = Product::query()->create(['name' => 'E-book', 'price' => '5', 'product_type' => 'digital']);
    $mug = Product::query()->create(['name' => 'Mug', 'price' => '5', 'product_type' => 'simple']);
    expect(app(ShippingService::class)->calculateShippingCost($method, [$ebook]))->toBe('0.0000')
        ->and(app(ShippingService::class)->calculateShippingCost($method, [['product' => $ebook], ['product' => $mug]]))->toBe('1500.0000');
});

it('manages drivers and signs them in with an SMS code and a PIN', function (): void {
    tenancy()->initialize($this->tenant);
    SmsGatewaySetting::query()->create(['provider' => 'termii', 'credentials' => ['api_key' => 'k', 'sender_id' => 'Shop'], 'is_active' => true, 'is_default' => true]);
    Http::fake(['*/api/sms/send' => Http::response(['message_id' => 'm1'])]);

    $driver = $this->tenantJson('POST', '/api/admin/drivers', ['name' => 'Tunde', 'phone' => '+234 801-234-5678', 'vehicle_type' => 'motorbike'], $this->auth)
        ->assertCreated()->assertJsonPath('data.phone', '+2348012345678')->assertJsonPath('data.has_pin', false)->json('data');
    $this->tenantJson('POST', '/api/admin/drivers', ['name' => 'Dup', 'phone' => '+2348012345678'], $this->auth)->assertStatus(422)->assertJsonValidationErrors('phone');

    // Unknown numbers get the same answer and no message.
    $this->tenantJson('POST', '/api/driver/auth/request-otp', ['phone' => '+2340000000000'])->assertOk();
    Http::assertNothingSent();

    $this->tenantJson('POST', '/api/driver/auth/request-otp', ['phone' => '+234 801 234 5678'])->assertOk();
    $sent = Http::recorded()[0][0];
    expect($sent['to'])->toBe('2348012345678');
    preg_match('/code is (\d{6})/', (string) $sent['sms'], $match);
    $code = $match[1];

    $this->tenantJson('POST', '/api/driver/auth/verify-otp', ['phone' => '+2348012345678', 'otp' => $code === '000000' ? '111111' : '000000', 'pin' => '4321', 'pin_confirmation' => '4321'])
        ->assertStatus(422)->assertJsonValidationErrors('otp');
    $this->tenantJson('POST', '/api/driver/auth/verify-otp', ['phone' => '+2348012345678', 'otp' => $code, 'pin' => '4321', 'pin_confirmation' => '4321'])->assertOk();
    $this->tenantJson('POST', '/api/driver/auth/verify-otp', ['phone' => '+2348012345678', 'otp' => $code, 'pin' => '9999', 'pin_confirmation' => '9999'])->assertStatus(422);

    // auth-sensitive allows 5 attempts a minute per number, whatever its format.
    $this->tenantJson('POST', '/api/driver/auth/login', ['phone' => '+234 801 234 5678', 'pin' => '0000'])->assertStatus(422);
    $this->tenantJson('POST', '/api/driver/auth/login', ['phone' => '2348012345678', 'pin' => '0000'])->assertStatus(429)->assertHeader('Retry-After');
    $this->travel(61)->seconds();

    $this->tenantJson('POST', '/api/driver/auth/login', ['phone' => '+2348012345678', 'pin' => '0000'])->assertStatus(422);
    $token = $this->tenantJson('POST', '/api/driver/auth/login', ['phone' => '+2348012345678', 'pin' => '4321'])->assertOk()
        ->assertJsonPath('data.driver.has_pin', true)->json('data.token');
    $driverAuth = ['Authorization' => 'Bearer '.$token];

    $this->tenantJson('GET', '/api/driver/profile', [], $driverAuth)->assertOk()->assertJsonPath('data.name', 'Tunde')->assertJsonMissingPath('data.pin_hash');
    $this->tenantJson('PATCH', '/api/driver/availability', ['is_available' => false], $driverAuth)->assertOk()->assertJsonPath('data.is_available', false);
    $this->tenantJson('POST', '/api/driver/push-tokens', ['token' => 'fcm-1', 'platform' => 'android'], $driverAuth)->assertCreated();

    // Tokens never cross actors.
    $this->tenantJson('GET', '/api/admin/drivers', [], $driverAuth)->assertUnauthorized();
    $this->tenantJson('GET', '/api/driver/profile', [], $this->auth)->assertUnauthorized();

    // Deactivation revokes the driver's sessions and blocks sign-in.
    $this->tenantJson('DELETE', "/api/admin/drivers/{$driver['id']}", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->tenantJson('GET', '/api/driver/profile', [], $driverAuth)->assertUnauthorized();
    $this->tenantJson('POST', '/api/driver/auth/login', ['phone' => '+2348012345678', 'pin' => '4321'])->assertForbidden()->assertJsonPath('meta.error_code', 'account_disabled');
    $this->tenantJson('PATCH', "/api/admin/drivers/{$driver['id']}/availability", ['is_available' => true], $this->auth)->assertStatus(422);
});

it('caps OTP attempts and refuses codes when no SMS gateway is configured', function (): void {
    $this->tenantJson('POST', '/api/driver/auth/request-otp', ['phone' => '+2348012345678'])->assertStatus(503)->assertJsonPath('meta.error_code', 'sms_unavailable');

    tenancy()->initialize($this->tenant);
    SmsGatewaySetting::query()->create(['provider' => 'termii', 'credentials' => ['api_key' => 'k', 'sender_id' => 'Shop'], 'is_active' => true, 'is_default' => true]);
    Http::fake(['*/api/sms/send' => Http::response(['message_id' => 'm1'])]);
    Driver::query()->create(['name' => 'Ada', 'phone' => '+2348000000001'])->forceFill(['status' => 'active'])->save();

    app(DriverAuthService::class)->requestOtp('+2348000000001');
    preg_match('/code is (\d{6})/', (string) Http::recorded()[0][0]['sms'], $match);
    $wrong = $match[1] === '123456' ? '654321' : '123456';

    for ($attempt = 1; $attempt <= DriverAuthService::OTP_MAX_ATTEMPTS; $attempt++) {
        $this->tenantJson('POST', '/api/driver/auth/verify-otp', ['phone' => '+2348000000001', 'otp' => $wrong, 'pin' => '1234', 'pin_confirmation' => '1234'])->assertStatus(422);
    }

    // The right code no longer works once the attempts are used up.
    $this->travel(61)->seconds();
    $this->tenantJson('POST', '/api/driver/auth/verify-otp', ['phone' => '+2348000000001', 'otp' => $match[1], 'pin' => '1234', 'pin_confirmation' => '1234'])->assertStatus(422);
});
