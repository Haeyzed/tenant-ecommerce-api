<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(TenantSettingsService::class)->set('timezone', 'Africa/Lagos');
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->bola = User::query()->create(['name' => 'Bola Ade', 'email' => 'bola@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->bola->assignRole('staff');
    shiftTokens();

    $this->tenantJson('POST', '/api/admin/modules/hr/enable', [], $this->staff)->assertOk();
    $this->bolaEmployee = $this->tenantJson('POST', '/api/admin/hr/employees', ['user_id' => $this->bola->id, 'employment_type' => 'full_time', 'hire_date' => '2026-01-05'], $this->staff)
        ->assertCreated()->json('data');
    $this->chidi = $this->tenantJson('POST', '/api/admin/hr/employees', ['first_name' => 'Chidi', 'last_name' => 'Obi', 'employment_type' => 'full_time', 'hire_date' => '2026-01-05'], $this->staff)
        ->assertCreated()->json('data');

    // A Monday well ahead, in Lagos.
    $this->monday = CarbonImmutable::now('Africa/Lagos')->addWeeks(2)->startOfWeek();
});

/**
 * Owner and Bola tokens; minted again after travelling in time.
 */
function shiftTokens(): void
{
    tenancy()->initialize(test()->tenant);
    test()->staff = ['Authorization' => 'Bearer '.test()->owner->createToken('t', ['staff'])->plainTextToken];
    test()->bolaAuth = ['Authorization' => 'Bearer '.test()->bola->createToken('t', ['staff'])->plainTextToken];
}

function shiftTravel(CarbonImmutable $day, string $time): void
{
    test()->travelTo(CarbonImmutable::parse($day->toDateString().' '.$time, 'Africa/Lagos'));
    shiftTokens();
}

it('keeps shift templates valid and rosters employees by weekday, with a self-service schedule', function (): void {
    $night = $this->tenantJson('POST', '/api/admin/hr/shifts', ['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 30], $this->staff)
        ->assertCreated()->assertJsonPath('data.is_overnight', true)->assertJsonPath('data.scheduled_minutes', 450)->json('data');
    $morning = $this->tenantJson('POST', '/api/admin/hr/shifts', ['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00'], $this->staff)
        ->assertCreated()->assertJsonPath('data.is_overnight', false)->assertJsonPath('data.scheduled_minutes', 480)->json('data');
    $this->tenantJson('POST', '/api/admin/hr/shifts', ['name' => 'Bad', 'start_time' => '09:00', 'end_time' => '09:00'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'shift_times_invalid');
    $this->tenantJson('POST', '/api/admin/hr/shifts', ['name' => 'Bad', 'start_time' => '09:00', 'end_time' => '10:00', 'break_minutes' => 60], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'shift_break_too_long');
    $this->tenantJson('POST', '/api/admin/hr/shifts', ['name' => 'Mine', 'start_time' => '09:00', 'end_time' => '17:00'], $this->bolaAuth)->assertForbidden();

    // Weekdays only for a fortnight: ten shifts each; the repeat skips, replace swaps.
    $week = ['from' => $this->monday->toDateString(), 'to' => $this->monday->addDays(13)->toDateString(), 'weekdays' => [1, 2, 3, 4, 5]];
    $this->tenantJson('POST', '/api/admin/hr/roster/assign', ['employee_ids' => [$this->bolaEmployee['id'], $this->chidi['id']], 'shift_id' => $morning['id']] + $week, $this->staff)
        ->assertOk()->assertJsonPath('data', ['assigned' => 20, 'replaced' => 0, 'skipped' => 0]);
    $this->tenantJson('POST', '/api/admin/hr/roster/assign', ['employee_ids' => [$this->bolaEmployee['id']], 'shift_id' => $morning['id']] + $week, $this->staff)
        ->assertOk()->assertJsonPath('data.skipped', 10);
    $this->tenantJson('POST', '/api/admin/hr/roster/assign', ['employee_ids' => [$this->bolaEmployee['id']], 'shift_id' => $night['id'], 'replace' => true,
        'from' => $this->monday->toDateString(), 'to' => $this->monday->toDateString()], $this->staff)->assertOk()->assertJsonPath('data.replaced', 1);

    $this->tenantJson('GET', '/api/admin/hr/roster?from='.$this->monday->toDateString().'&to='.$this->monday->addDays(6)->toDateString(), [], $this->staff)
        ->assertOk()->assertJsonCount(10, 'data');
    $this->tenantJson('GET', '/api/admin/hr/roster', [], $this->bolaAuth)->assertForbidden();
    $this->tenantJson('GET', '/api/admin/hr/my-shifts?from='.$this->monday->toDateString(), [], $this->bolaAuth)
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.shift.name', 'Night')->assertJsonPath('data.1.shift.name', 'Morning');

    // A rostered shift is kept for history; removal clears the roster.
    $this->tenantJson('DELETE', "/api/admin/hr/shifts/{$morning['id']}", [], $this->staff)->assertStatus(409)->assertJsonPath('meta.error_code', 'shift_in_use');
    $this->tenantJson('POST', '/api/admin/hr/roster/unassign', ['employee_ids' => [$this->chidi['id']], 'from' => $week['from'], 'to' => $week['to']], $this->staff)
        ->assertOk()->assertJsonPath('data.removed', 10);
});

it('keeps a night shift on one row and records overtime for approval only when enabled', function (): void {
    $night = $this->tenantJson('POST', '/api/admin/hr/shifts', ['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 30], $this->staff)->json('data');
    $this->tenantJson('POST', '/api/admin/hr/roster/assign', ['employee_ids' => [$this->bolaEmployee['id']], 'shift_id' => $night['id'],
        'from' => $this->monday->toDateString(), 'to' => $this->monday->addDay()->toDateString()], $this->staff)->assertOk();
    $this->tenantJson('PUT', '/api/admin/hr/settings', ['overtime_enabled' => true, 'overtime_minimum_minutes' => 30], $this->staff)
        ->assertOk()->assertJsonPath('data.overtime_enabled', true)->assertJsonPath('data.overtime_rate_multiplier', '1.50');

    // Monday 22:10 in, Tuesday 07:10 out: 540 on the clock − 30 break = 510 worked vs 450 scheduled.
    shiftTravel($this->monday, '22:10');
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', [], $this->bolaAuth)->assertCreated()
        ->assertJsonPath('data.work_date', $this->monday->toDateString())->assertJsonPath('data.shift.name', 'Night')->assertJsonPath('data.is_late', true);
    shiftTravel($this->monday->addDay(), '07:10');
    $row = $this->tenantJson('POST', '/api/admin/hr/attendance/clock-out', [], $this->bolaAuth)->assertOk()
        ->assertJsonPath('data.work_date', $this->monday->toDateString())
        ->assertJsonPath('data.is_early_leave', false)
        ->assertJsonPath('data.scheduled_minutes', 450)
        ->assertJsonPath('data.worked_minutes', 510)
        ->assertJsonPath('data.overtime', ['minutes' => 60, 'status' => 'pending', 'approved_minutes' => null, 'decided_by' => null, 'decided_at' => null, 'note' => null])
        ->json('data');

    // Chidi has no roster: the 09:00–17:00 settings apply; 20 extra minutes is under the minimum.
    shiftTravel($this->monday->addDay(), '09:00');
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', ['employee_id' => $this->chidi['id']], $this->staff)->assertCreated()->assertJsonPath('data.shift', null);
    shiftTravel($this->monday->addDay(), '17:20');
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-out', ['employee_id' => $this->chidi['id']], $this->staff)->assertOk()
        ->assertJsonPath('data.worked_minutes', 500)->assertJsonPath('data.overtime.status', 'none');

    // Review: only pending rows, only up to the recorded minutes, once.
    $this->tenantJson('GET', '/api/admin/hr/overtime', [], $this->staff)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $row['id']);
    $this->tenantJson('POST', "/api/admin/hr/attendance/{$row['id']}/overtime/approve", ['minutes' => 45], $this->bolaAuth)->assertForbidden();
    $this->tenantJson('POST', "/api/admin/hr/attendance/{$row['id']}/overtime/approve", ['minutes' => 61], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'overtime_exceeds_recorded');
    $this->tenantJson('POST', "/api/admin/hr/attendance/{$row['id']}/overtime/approve", ['minutes' => 45, 'note' => 'Stock count'], $this->staff)->assertOk()
        ->assertJsonPath('data.overtime.status', 'approved')->assertJsonPath('data.overtime.approved_minutes', 45)->assertJsonPath('data.overtime.decided_by.name', 'Owner');
    $this->tenantJson('POST', "/api/admin/hr/attendance/{$row['id']}/overtime/reject", [], $this->staff)->assertStatus(422);

    $summary = collect($this->tenantJson('GET', '/api/admin/hr/attendance/summary?from='.$this->monday->toDateString().'&to='.$this->monday->addDay()->toDateString(), [], $this->staff)
        ->assertOk()->json('data.employees'))->keyBy('employee_id');
    expect($summary[$this->bolaEmployee['id']])->toMatchArray(['worked_minutes' => 510, 'overtime_pending_minutes' => 0, 'overtime_approved_minutes' => 45]);
});

it('pays approved overtime in the payroll run at the hourly rate and multiplier', function (): void {
    $this->tenantJson('POST', '/api/admin/modules/hr_payroll/enable', [], $this->staff)->assertOk();
    $this->tenantJson('PUT', '/api/admin/hr/settings', ['overtime_enabled' => true, 'overtime_minimum_minutes' => 15], $this->staff)->assertOk();
    // 173,330 a month over 173.33 standard hours = 1,000 an hour; Chidi has his own 2,000 rate.
    $this->tenantJson('POST', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/salary", ['base_salary' => '173330', 'effective_from' => '2026-01-05'], $this->staff)->assertCreated();
    $this->tenantJson('POST', "/api/admin/hr/employees/{$this->chidi['id']}/salary", ['base_salary' => '100000', 'hourly_rate' => '2000', 'effective_from' => '2026-01-05'], $this->staff)
        ->assertCreated()->assertJsonPath('data.hourly_rate', '2000.0000');

    foreach ([[$this->bolaEmployee['id'], '18:00', 45], [$this->chidi['id'], '17:30', 30]] as [$employeeId, $out, $approve]) {
        shiftTravel($this->monday, '09:00');
        $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', ['employee_id' => $employeeId], $this->staff)->assertCreated();
        shiftTravel($this->monday, $out);
        $id = $this->tenantJson('POST', '/api/admin/hr/attendance/clock-out', ['employee_id' => $employeeId], $this->staff)->assertOk()->json('data.id');
        $this->tenantJson('POST', "/api/admin/hr/attendance/{$id}/overtime/approve", ['minutes' => $approve], $this->staff)->assertOk();
    }

    $run = $this->tenantJson('POST', '/api/admin/hr/payroll-runs', ['period_start' => $this->monday->startOfMonth()->toDateString(), 'period_end' => $this->monday->endOfMonth()->toDateString()], $this->staff)
        ->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/admin/hr/payroll-runs/{$run['id']}/generate-items", [], $this->staff)->assertOk();
    $items = collect($this->tenantJson('GET', "/api/admin/hr/payroll-runs/{$run['id']}/items", [], $this->staff)->assertOk()->json('data'))->keyBy('employee.id');

    // 0.75 h × 1,000 × 1.5 = 1,125; 0.5 h × 2,000 × 1.5 = 1,500.
    expect($items[$this->bolaEmployee['id']]['lines'])->toHaveCount(1)
        ->and($items[$this->bolaEmployee['id']]['lines'][0])->toMatchArray(['type' => 'overtime', 'amount' => '1125.0000', 'label' => 'Overtime 0.75 h × 1.5'])
        ->and($items[$this->bolaEmployee['id']]['gross_pay'])->toBe('174455.0000')
        ->and($items[$this->chidi['id']]['lines'][0]['amount'])->toBe('1500.0000')
        ->and($items[$this->chidi['id']]['gross_pay'])->toBe('101500.0000');

    // The HR dashboard: 75 approved minutes and 2,625 overtime pay this month.
    $kpis = collect($this->tenantJson('GET', '/api/admin/dashboard/hr?range=this_month&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key');
    expect($kpis['overtime_approved_minutes'])->toBe(75)
        ->and((float) $kpis['overtime_pay'])->toBe(2625.0);
});

it('shows who is in, rostered, absent and late today on the HR dashboard', function (): void {
    $morning = $this->tenantJson('POST', '/api/admin/hr/shifts', ['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00'], $this->staff)->json('data');
    $this->tenantJson('POST', '/api/admin/hr/roster/assign', ['employee_ids' => [$this->bolaEmployee['id'], $this->chidi['id']], 'shift_id' => $morning['id'],
        'from' => $this->monday->toDateString(), 'to' => $this->monday->toDateString()], $this->staff)->assertOk();

    // 07:00: Bola arrives an hour late; Chidi never comes.
    shiftTravel($this->monday, '07:00');
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', [], $this->bolaAuth)->assertCreated()->assertJsonPath('data.is_late', true);

    $kpis = collect($this->tenantJson('GET', '/api/admin/dashboard/hr?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($kpis)->toMatchArray([
        'headcount' => 2, 'clocked_in_now' => 1, 'rostered_today' => 2, 'absent_today' => 1, 'late_arrivals' => 1, 'pending_overtime' => 0,
    ])->not->toHaveKey('overtime_pay');
});
