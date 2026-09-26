<?php

declare(strict_types=1);

use App\Modules\CustomFields\Models\CustomFieldDefinition;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    // Staff users stand in as a further core entity, and supplier as a module one.
    $registry = app(CustomFieldEntityRegistry::class);
    $registry->register('staff_user', User::class, 'users');
    $registry->register('supplier', User::class, 'suppliers', 'purchasing');

    tenancy()->initialize($this->tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
});

function field(array $overrides = []): array
{
    return array_merge(['entity_type' => 'staff_user', 'key' => 'tax_number', 'label' => 'Tax number', 'field_type' => 'text'], $overrides);
}

it('creates definitions with immutable key and type and lists only offered entities', function (): void {
    $id = $this->tenantJson('POST', '/api/admin/custom-fields', field(['validation' => ['max_length' => 20, 'pattern' => '^[A-Z0-9-]+$']]), $this->auth)
        ->assertCreated()->assertJsonPath('data.key', 'tax_number')->assertJsonPath('data.validation.max_length', 20)->json('data.id');

    $this->tenantJson('PATCH', "/api/admin/custom-fields/{$id}", ['field_type' => 'number'], $this->auth)->assertStatus(422)->assertJsonValidationErrors('field_type');
    $this->tenantJson('PATCH', "/api/admin/custom-fields/{$id}", ['label' => 'VAT number'], $this->auth)->assertOk()->assertJsonPath('data.label', 'VAT number');
    $this->tenantJson('POST', '/api/admin/custom-fields', field(), $this->auth)->assertStatus(422)->assertJsonValidationErrors('key');
    $this->tenantJson('POST', '/api/admin/custom-fields', field(['key' => 'bad', 'validation' => ['pattern' => '(a+)+$']]), $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('validation.pattern');

    // The purchasing module is not on the basic plan: its entity is not offered.
    $this->tenantJson('GET', '/api/admin/custom-fields/entities', [], $this->auth)->assertOk()->assertJsonPath('data', [
        ['entity_type' => 'customer', 'owner_module' => null, 'state' => 'core'],
        ['entity_type' => 'product', 'owner_module' => null, 'state' => 'core'],
        ['entity_type' => 'product_variant', 'owner_module' => null, 'state' => 'core'],
        ['entity_type' => 'staff_user', 'owner_module' => null, 'state' => 'core'],
    ]);
    $this->tenantJson('POST', '/api/admin/custom-fields', field(['entity_type' => 'supplier']), $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'custom_field_entity_unknown');
});

it('validates, stores, presents, filters and searches typed values', function (): void {
    $definitions = [
        field(['validation' => ['max_length' => 20], 'is_searchable' => true, 'is_filterable' => true]),
        field(['key' => 'employees', 'label' => 'Employees', 'field_type' => 'number', 'validation' => ['min' => 1], 'is_filterable' => true]),
        field(['key' => 'segment', 'label' => 'Segment', 'field_type' => 'select', 'options' => [['value' => 'smb', 'label' => 'SMB'], ['value' => 'ent', 'label' => 'Enterprise']], 'is_required' => true]),
        field(['key' => 'channels', 'label' => 'Channels', 'field_type' => 'multi_select', 'options' => [['value' => 'web', 'label' => 'Web'], ['value' => 'pos', 'label' => 'POS']], 'is_filterable' => true]),
        field(['key' => 'onboarded_at', 'label' => 'Onboarded', 'field_type' => 'datetime']),
        field(['key' => 'vip', 'label' => 'VIP', 'field_type' => 'boolean', 'default_value' => false]),
    ];

    foreach ($definitions as $definition) {
        $this->tenantJson('POST', '/api/admin/custom-fields', $definition, $this->auth)->assertCreated();
    }

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('timezone', 'Africa/Lagos');
    $service = app(CustomFieldService::class);

    expect(fn () => $service->validate('staff_user', ['employees' => 0, 'unknown' => 'x'], CustomFieldService::ADMIN, true))
        ->toThrow(fn (ValidationException $e) => expect(array_keys($e->errors()))->toEqualCanonicalizing(['custom_fields.unknown', 'custom_fields.employees', 'custom_fields.segment']));

    $values = $service->validate('staff_user', [
        'tax_number' => ' TX-1 ', 'employees' => '12', 'segment' => 'smb', 'channels' => ['web', 'pos'], 'onboarded_at' => '2026-09-01 10:00',
    ], CustomFieldService::ADMIN, true);

    expect($values['tax_number'])->toBe('TX-1')->and($values['onboarded_at'])->toBe('2026-09-01 09:00:00')->and($values['vip'])->toBe('0');

    $other = User::query()->create(['name' => 'Other', 'email' => 'other@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $service->save($this->owner, 'staff_user', $values);
    $service->save($other, 'staff_user', $service->validate('staff_user', ['employees' => 3, 'segment' => 'ent', 'channels' => ['pos']], CustomFieldService::ADMIN, true));

    expect($service->valuesFor($this->owner, 'staff_user', CustomFieldService::ADMIN))->toMatchArray([
        'tax_number' => 'TX-1', 'employees' => 12, 'segment' => 'smb', 'channels' => ['web', 'pos'],
        'onboarded_at' => '2026-09-01T10:00:00+01:00', 'vip' => false,
    ]);

    $filter = fn (array $filters): array => $service->applyFilters(User::query(), 'staff_user', $filters)->pluck('id')->all();
    expect($filter(['employees' => ['from' => 10]]))->toBe([$this->owner->id])
        ->and($filter(['channels' => ['pos']]))->toEqualCanonicalizing([$this->owner->id, $other->id])
        ->and($service->applySearch(User::query()->where('name', 'nobody'), 'staff_user', 'TX')->pluck('id')->all())->toBe([$this->owner->id]);

    // Clearing a value deletes it; a required field cannot be cleared.
    $service->save($this->owner, 'staff_user', $service->validate('staff_user', ['tax_number' => null], CustomFieldService::ADMIN));
    expect($service->valuesFor($this->owner, 'staff_user', CustomFieldService::ADMIN)['tax_number'])->toBeNull();
    expect(fn () => $service->validate('staff_user', ['segment' => ''], CustomFieldService::ADMIN))->toThrow(ValidationException::class);

    // Options in use cannot be removed, and a field with values cannot be deleted.
    $segment = CustomFieldDefinition::query()->where('key', 'segment')->firstOrFail();
    $this->tenantJson('PATCH', "/api/admin/custom-fields/{$segment->id}", ['options' => [['value' => 'smb', 'label' => 'Small']]], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'custom_field_option_in_use');
    $this->tenantJson('DELETE', "/api/admin/custom-fields/{$segment->id}", [], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'custom_field_has_values');
    $this->tenantJson('GET', "/api/admin/custom-fields/{$segment->id}", [], $this->auth)->assertOk()->assertJsonPath('data.value_count', 2);
});

it('hides admin-only fields from public contexts and enforces the field limit', function (): void {
    $this->tenantJson('POST', '/api/admin/custom-fields', field(), $this->auth)->assertCreated();
    $this->tenantJson('POST', '/api/admin/custom-fields', field(['key' => 'nickname', 'label' => 'Nickname', 'is_admin_only' => false]), $this->auth)->assertCreated();

    tenancy()->initialize($this->tenant);
    expect(array_keys(app(CustomFieldService::class)->definitions('staff_user', CustomFieldService::PUBLIC)))->toBe(['nickname']);

    $this->tenantJson('GET', '/api/storefront/custom-fields?entity_type=customer', [])->assertOk()->assertJsonPath('data', []);

    tenancy()->initialize($this->tenant);
    $limit = (int) app(PlanLimitService::class)->getLimit($this->tenant, 'max_custom_fields');

    for ($i = 2; $i < $limit; $i++) {
        $this->tenantJson('POST', '/api/admin/custom-fields', field(['key' => 'f'.$i, 'label' => 'F'.$i]), $this->auth)->assertCreated();
    }

    $this->tenantJson('POST', '/api/admin/custom-fields', field(['key' => 'over', 'label' => 'Over']), $this->auth)
        ->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');
});
