<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    // Monday 5 October 2026, 08:00 in Lagos (UTC+1).
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'Africa/Lagos'));
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('timezone', 'Africa/Lagos');
    app(WarehouseService::class)->ensureDefault();
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];
    $this->stylist = User::query()->create(['name' => 'Kemi', 'email' => 'kemi@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);
    $this->bola = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@shop.test', 'password' => 'Secret123']);
    $this->adaAuth = ['Authorization' => 'Bearer '.$this->ada->createToken('t', ['customer'])->plainTextToken];
    $this->bolaAuth = ['Authorization' => 'Bearer '.$this->bola->createToken('t', ['customer'])->plainTextToken];

    $this->tenantJson('POST', '/api/admin/modules/booking/enable', [], $this->staff)->assertOk();
});

function slotTimes(): array
{
    return array_map(static fn (array $s): string => substr($s['start'], 11, 5),
        test()->tenantJson('GET', '/api/products/'.test()->haircut['id'].'/available-slots?date=2026-10-06')->assertOk()->json('data.slots'));
}

it('offers open slots and books each one once, with or without prepayment', function (): void {
    // Only a service with a duration can be booked.
    $this->tenantJson('POST', '/api/admin/products', ['name' => 'Comb', 'price' => '5', 'is_bookable' => true], $this->staff)->assertStatus(422)->assertJsonValidationErrors('is_bookable');
    $this->tenantJson('POST', '/api/admin/products', ['name' => 'Cut', 'price' => '5', 'product_type' => 'service', 'is_bookable' => true], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
    $this->haircut = $this->tenantJson('POST', '/api/admin/products', ['name' => 'Haircut', 'price' => '5000', 'product_type' => 'service', 'is_bookable' => true, 'duration_minutes' => 60], $this->staff)
        ->assertCreated()->assertJsonPath('data.is_bookable', true)->json('data');

    $member = $this->tenantJson('POST', '/api/admin/booking-staff', ['user_id' => $this->stylist->id], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('POST', '/api/admin/booking-staff', ['user_id' => $this->stylist->id], $this->staff)->assertStatus(409);
    $this->tenantJson('POST', "/api/admin/products/{$this->haircut['id']}/booking-staff/{$member['id']}", [], $this->staff)->assertOk();
    $this->tenantJson('PUT', "/api/admin/booking-staff/{$member['id']}/availability", ['slots' => [
        ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '12:00'], ['day_of_week' => 2, 'start_time' => '11:30', 'end_time' => '13:00'],
    ]], $this->staff)->assertStatus(422);
    $this->tenantJson('PUT', "/api/admin/booking-staff/{$member['id']}/availability", ['slots' => [['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '12:00']]], $this->staff)
        ->assertOk()->assertJsonPath('data.availability.0.start_time', '09:00');

    expect(slotTimes())->toBe(['09:00', '10:00', '11:00']);
    $this->tenantJson('POST', "/api/admin/booking-staff/{$member['id']}/time-off", ['start_datetime' => '2026-10-06 10:00', 'end_datetime' => '2026-10-06 11:00', 'reason' => 'Training'], $this->staff)
        ->assertCreated()->assertJsonPath('data.start_datetime', '2026-10-06T10:00:00+01:00');
    expect(slotTimes())->toBe(['09:00', '11:00']);

    // A guest books 09:00 (no prepayment: confirmed at once); nobody gets it twice or off the grid.
    $book = fn (string $time, array $extra = [], array $headers = []) => $this->tenantJson('POST', '/api/bookings',
        ['product_id' => $this->haircut['id'], 'booking_staff_id' => $member['id'], 'start_datetime' => "2026-10-06 {$time}", ...$extra], $headers);
    $book('09:00')->assertStatus(422)->assertJsonPath('meta.error_code', 'guest_details_required');
    $guest = $book('09:00', ['guest_name' => 'Chidi', 'guest_email' => 'chidi@guest.test'])->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.end_datetime', '2026-10-06T10:00:00+01:00')->assertJsonMissingPath('data.guest_email')->json('data');
    $book('09:00', [], $this->bolaAuth)->assertStatus(409)->assertJsonPath('meta.error_code', 'slot_unavailable');
    $book('09:30', [], $this->bolaAuth)->assertStatus(409);
    expect(slotTimes())->toBe(['11:00']);

    // Prepayment on: Ada's booking holds the slot until its order is confirmed.
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('booking_requires_prepayment', true);
    $held = $book('11:00', [], $this->adaAuth)->assertCreated()->assertJsonPath('data.status', 'pending_payment')->json('data');
    expect(slotTimes())->toBe([]);
    tenancy()->initialize($this->tenant);
    $order = Order::query()->findOrFail($held['order']['id']);
    expect([$order->order_source, (string) $order->total, $order->payment_expires_at !== null])->toBe(['online', '5000.0000', true]);
    app(OrderService::class)->confirmOrder($order);
    $this->tenantJson('GET', '/api/account/bookings', [], $this->adaAuth)->assertOk()->assertJsonPath('data.0.status', 'confirmed');

    // Only Ada can cancel it; the slot opens again.
    $this->tenantJson('PATCH', "/api/account/bookings/{$held['id']}/cancel", [], $this->bolaAuth)->assertNotFound();
    $this->tenantJson('PATCH', "/api/account/bookings/{$held['id']}/cancel", [], $this->adaAuth)->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(slotTimes())->toBe(['11:00']);

    // Staff move Chidi to 11:00 and bill him once.
    $this->tenantJson('PATCH', "/api/admin/bookings/{$guest['id']}/reschedule", ['start_datetime' => '2026-10-06 11:00'], $this->staff)->assertOk()
        ->assertJsonPath('data.start_datetime', '2026-10-06T11:00:00+01:00');
    expect(slotTimes())->toBe(['09:00']);
    $invoice = $this->tenantJson('POST', "/api/admin/bookings/{$guest['id']}/invoice", [], $this->staff)->assertCreated()->assertJsonPath('data.booking.order.total', '5000.0000')->json('data');
    $this->tenantJson('POST', "/api/admin/bookings/{$guest['id']}/invoice", [], $this->staff)->assertStatus(409)->assertJsonPath('meta.error_code', 'booking_already_invoiced');
    tenancy()->initialize($this->tenant);
    expect(Order::query()->findOrFail($invoice['order_id'])->only(['order_source', 'customer_email']))->toBe(['order_source' => 'admin', 'customer_email' => 'chidi@guest.test']);

    // An unpaid prepaid booking expires with its order.
    $late = $book('09:00', [], $this->bolaAuth)->assertCreated()->assertJsonPath('data.status', 'pending_payment')->json('data');
    $this->travel(2)->hours();
    tenancy()->initialize($this->tenant);
    expect(app(OrderService::class)->expireUnpaidOrder(Order::query()->findOrFail($late['order']['id'])))->toBe('cancelled')
        ->and(Booking::query()->findOrFail($late['id'])->status)->toBe('cancelled')
        ->and(slotTimes())->toBe(['09:00']);

    // After the appointment.
    $this->tenantJson('PATCH', "/api/admin/bookings/{$guest['id']}/no-show", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'booking_not_started');
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:30', 'Africa/Lagos'));
    $this->tenantJson('PATCH', "/api/admin/bookings/{$guest['id']}/complete", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'completed');
    $this->tenantJson('PATCH', "/api/admin/bookings/{$guest['id']}/no-show", [], $this->staff)->assertStatus(422);
    $this->tenantJson('GET', '/api/admin/bookings?status=completed&from=2026-10-06&to=2026-10-06', [], $this->staff)->assertOk()
        ->assertJsonPath('data.0.guest_email', 'chidi@guest.test');

    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/booking?range=custom&from=2026-10-06&to=2026-10-06&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($section)->toMatchArray(['completed' => 1, 'no_shows' => 0]);
});
