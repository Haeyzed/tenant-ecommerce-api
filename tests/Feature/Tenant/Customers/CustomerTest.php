<?php

declare(strict_types=1);

use App\Modules\Customers\Events\CustomerAuthenticated;
use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Exports\Jobs\GenerateExport;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);
});

// The export test writes the file on the test tenant's real disk (the tenancy
// filesystem bootstrapper re-roots disks, so Storage::fake cannot isolate it).
afterEach(function (): void {
    foreach (glob(base_path('storage/tenants/test-tenant-a/app/*/customer_personal_data-*.json')) ?: [] as $file) {
        File::delete($file);
        @rmdir(dirname($file));
    }
});

function registerCustomer(array $overrides = [], array $headers = []): array
{
    return test()->tenantJson('POST', '/api/auth/register', array_merge([
        'name' => 'Ada Obi', 'email' => 'Ada@Shop.test', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
    ], $overrides), $headers)->assertCreated()->json('data');
}

it('registers and signs in customers on their own guard, announcing the guest token', function (): void {
    Event::fake([CustomerAuthenticated::class]);
    $guest = str_repeat('a', 40);

    $data = registerCustomer([], ['X-Guest-Token' => $guest]);
    expect($data['customer']['email'])->toBe('ada@shop.test');
    Event::assertDispatched(CustomerAuthenticated::class, fn ($e): bool => $e->guestToken === $guest && $e->registered);

    tenancy()->initialize($this->tenant);
    $customer = Customer::query()->where('email', 'ada@shop.test')->firstOrFail();
    Notification::assertSentTo($customer, TemplatedNotification::class, fn ($n): bool => $n->key === 'customer.welcome');
    Notification::assertSentTo($customer, TemplatedNotification::class, fn ($n): bool => $n->key === 'customer.email_verification');
    expect(CustomerGroup::query()->where('is_default', true)->value('name'))->toBe('Standard');

    $token = $this->tenantJson('POST', '/api/auth/login', ['email' => 'ada@shop.test', 'password' => 'Secret123'])->assertOk()->json('data.token');
    $customerAuth = ['Authorization' => 'Bearer '.$token];

    $this->tenantJson('GET', '/api/account', [], $customerAuth)->assertOk()->assertJsonPath('data.name', 'Ada Obi');

    // Actor tokens never cross (§10.1).
    $this->tenantJson('GET', '/api/account', [], $this->staff)->assertUnauthorized();
    $this->tenantJson('GET', '/api/admin/customers', [], $customerAuth)->assertUnauthorized();

    $this->tenantJson('POST', '/api/auth/login', ['email' => 'ada@shop.test', 'password' => 'wrong'])->assertStatus(422);
});

it('keeps each customer to their own addresses with one default', function (): void {
    $auth = ['Authorization' => 'Bearer '.registerCustomer()['token']];
    $other = ['Authorization' => 'Bearer '.registerCustomer(['email' => 'bo@shop.test'])['token']];

    $first = $this->tenantJson('POST', '/api/account/addresses', ['recipient_name' => 'Ada', 'address_line_1' => '1 Main St', 'country_id' => 1], $auth)
        ->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
    $second = $this->tenantJson('POST', '/api/account/addresses', ['recipient_name' => 'Ada', 'address_line_1' => '2 Side St', 'country_id' => 1], $auth)
        ->assertCreated()->assertJsonPath('data.is_default', false)->json('data.id');

    $this->tenantJson('POST', "/api/account/addresses/{$second}/default", [], $auth)->assertOk()->assertJsonPath('data.is_default', true);
    $this->tenantJson('GET', '/api/account/addresses', [], $auth)->assertOk()->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.is_default', false);

    $this->tenantJson('PATCH', "/api/account/addresses/{$first}", ['label' => 'Hacked'], $other)->assertNotFound();
    $this->tenantJson('DELETE', "/api/account/addresses/{$first}", [], $other)->assertNotFound();
});

it('validates custom fields with the standard fields and hides admin-only ones from the customer', function (): void {
    $this->tenantJson('POST', '/api/admin/custom-fields', ['entity_type' => 'customer', 'key' => 'birthday', 'label' => 'Birthday', 'field_type' => 'date', 'is_admin_only' => false], $this->staff)->assertCreated();
    $this->tenantJson('POST', '/api/admin/custom-fields', ['entity_type' => 'customer', 'key' => 'credit_note', 'label' => 'Credit note', 'field_type' => 'text'], $this->staff)->assertCreated();

    $this->tenantJson('POST', '/api/auth/register', [
        'name' => '', 'email' => 'x@shop.test', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        'custom_fields' => ['birthday' => 'not-a-date', 'credit_note' => 'sneaky'],
    ])->assertStatus(422)->assertJsonValidationErrors(['name', 'custom_fields.birthday', 'custom_fields.credit_note']);

    $data = registerCustomer(['custom_fields' => ['birthday' => '1990-05-01']]);
    expect($data['customer']['custom_fields'])->toBe(['birthday' => '1990-05-01']);

    $id = $data['customer']['id'];
    $this->tenantJson('PATCH', "/api/admin/customers/{$id}", ['custom_fields' => ['credit_note' => 'VIP']], $this->staff)->assertOk()
        ->assertJsonPath('data.custom_fields', ['birthday' => '1990-05-01', 'credit_note' => 'VIP']);

    $this->tenantJson('GET', '/api/account', [], ['Authorization' => 'Bearer '.$data['token']])->assertOk()
        ->assertJsonPath('data.custom_fields', ['birthday' => '1990-05-01']);
});

it('anonymises a deleted account and keeps it from signing in', function (): void {
    $data = registerCustomer();
    $auth = ['Authorization' => 'Bearer '.$data['token']];
    $this->tenantJson('POST', '/api/account/addresses', ['recipient_name' => 'Ada', 'address_line_1' => '1 Main St', 'country_id' => 1], $auth)->assertCreated();

    $this->tenantJson('DELETE', '/api/account', ['current_password' => 'nope'], $auth)->assertStatus(422);
    $this->tenantJson('DELETE', '/api/account', ['current_password' => 'Secret123'], $auth)->assertOk();

    tenancy()->initialize($this->tenant);
    $customer = Customer::withTrashed()->findOrFail($data['customer']['id']);

    expect($customer->name)->toBe('Deleted customer')
        ->and($customer->email)->toBe('deleted+'.$customer->id.'@invalid')
        ->and($customer->phone)->toBeNull()
        ->and($customer->password)->toBeNull()
        ->and($customer->anonymized_at)->not->toBeNull()
        ->and($customer->trashed())->toBeTrue()
        ->and(Address::query()->where('customer_id', $customer->id)->count())->toBe(0);

    $this->tenantJson('GET', '/api/account', [], $auth)->assertUnauthorized();
    $this->tenantJson('POST', '/api/auth/login', ['email' => 'ada@shop.test', 'password' => 'Secret123'])->assertStatus(422);

    // The email is free again for a new account.
    registerCustomer();
});

it('exports a customer\'s personal data to the customer', function (): void {
    $data = registerCustomer();
    $auth = ['Authorization' => 'Bearer '.$data['token']];
    $this->tenantJson('POST', '/api/account/addresses', ['recipient_name' => 'Ada', 'address_line_1' => '1 Main St', 'country_id' => 1], $auth)->assertCreated();

    $this->tenantJson('POST', '/api/account/export', [], $auth)->assertStatus(202);

    tenancy()->initialize($this->tenant);
    $export = DataExport::query()->sole();
    expect($export->export_type)->toBe('customer_personal_data')
        ->and($export->requested_by_type)->toBe('customer')
        ->and($export->format)->toBe('json');

    app()->call([new GenerateExport($export->id), 'handle']);

    tenancy()->initialize($this->tenant);
    $content = (string) file_get_contents($export->refresh()->getFirstMediaPath('file'));
    expect($content)->toContain('"section":"account"')->toContain('1 Main St');
    Notification::assertSentTo(Customer::query()->find($data['customer']['id']), TemplatedNotification::class, fn ($n): bool => $n->key === 'account.data_export_ready');
});

it('manages customer groups with a protected default', function (): void {
    $customer = registerCustomer()['customer']['id'];

    $wholesale = $this->tenantJson('POST', '/api/admin/customer-groups', ['name' => 'Wholesale'], $this->staff)->assertCreated()->json('data.id');
    $this->tenantJson('POST', "/api/admin/customers/{$customer}/assign-group", ['customer_group_id' => $wholesale], $this->staff)
        ->assertOk()->assertJsonPath('data.customer_group.name', 'Wholesale');

    $default = collect($this->tenantJson('GET', '/api/admin/customer-groups', [], $this->staff)->json('data'))->firstWhere('is_default', true)['id'];
    $this->tenantJson('DELETE', "/api/admin/customer-groups/{$default}", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'default_group_protected');

    $this->tenantJson('DELETE', "/api/admin/customer-groups/{$wholesale}", [], $this->staff)->assertOk();
    $this->tenantJson('GET', "/api/admin/customers/{$customer}", [], $this->staff)->assertOk()->assertJsonPath('data.customer_group', null);
});
