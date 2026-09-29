<?php

declare(strict_types=1);

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Hr\Models\HrCandidate;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

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
    // A cashier with no HR permissions: self-service only.
    $this->bola = User::query()->create(['name' => 'Bola Ade', 'email' => 'bola@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->bola->assignRole('staff');
    hrTokens();

    $this->tenantJson('POST', '/api/admin/modules/hr/enable', [], $this->staff)->assertOk();
    $this->sales = $this->tenantJson('POST', '/api/admin/hr/departments', ['name' => 'Sales'], $this->staff)->assertCreated()->json('data');
    $this->bolaEmployee = $this->tenantJson('POST', '/api/admin/hr/employees', ['user_id' => $this->bola->id, 'department_id' => $this->sales['id'],
        'employment_type' => 'full_time', 'hire_date' => '2026-01-05', 'job_title' => 'Cashier'], $this->staff)
        ->assertCreated()->assertJsonPath('data.name', 'Bola Ade')->assertJsonPath('data.email', 'bola@a.test')->json('data');
    $this->chidi = $this->tenantJson('POST', '/api/admin/hr/employees', ['first_name' => 'Chidi', 'last_name' => 'Obi', 'email' => 'Chidi@Mail.test',
        'employment_type' => 'contract', 'hire_date' => '2026-03-01', 'employee_number' => 'E-002'], $this->staff)
        ->assertCreated()->assertJsonPath('data.email', 'chidi@mail.test')->assertJsonPath('data.user', null)->json('data');
});

/**
 * Owner and Bola tokens; minted again after travelling in time.
 */
function hrTokens(): void
{
    tenancy()->initialize(test()->tenant);
    test()->staff = ['Authorization' => 'Bearer '.test()->owner->createToken('t', ['staff'])->plainTextToken];
    test()->bolaAuth = ['Authorization' => 'Bearer '.test()->bola->createToken('t', ['staff'])->plainTextToken];
}

/**
 * The owner's view of a Lagos wall-clock time today.
 */
function lagos(string $time): CarbonImmutable
{
    return CarbonImmutable::parse(today('Africa/Lagos')->toDateString().' '.$time, 'Africa/Lagos');
}

it('keeps employees linked or standalone within the plan limit, with departments, attendance rules and documents', function (): void {
    // A linked employee takes the user's name; standalone fields are refused, and a user links once.
    $this->tenantJson('POST', '/api/admin/hr/employees', ['user_id' => $this->owner->id, 'first_name' => 'X', 'employment_type' => 'full_time', 'hire_date' => '2026-01-01'], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors('first_name');
    $this->tenantJson('POST', '/api/admin/hr/employees', ['user_id' => $this->bola->id, 'employment_type' => 'full_time', 'hire_date' => '2026-01-01'], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors('user_id');
    $this->tenantJson('POST', '/api/admin/hr/employees', ['employment_type' => 'full_time', 'hire_date' => '2026-01-01'], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors(['first_name', 'last_name']);
    $this->tenantJson('GET', '/api/admin/hr/employees', [], $this->bolaAuth)->assertForbidden();

    // max_employees counts active employees.
    tenancy()->initialize($this->tenant);
    PlanLimit::query()->where('limit_key', 'max_employees')->update(['limit_value' => 2]);
    app(PlanLimitService::class)->flush($this->tenant);
    $third = ['first_name' => 'Dayo', 'last_name' => 'Ola', 'employment_type' => 'part_time', 'hire_date' => '2026-04-01'];
    $this->tenantJson('POST', '/api/admin/hr/employees', $third, $this->staff)->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');
    $this->tenantJson('POST', "/api/admin/hr/employees/{$this->chidi['id']}/deactivate", ['termination_date' => '2026-09-30'], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.termination_date', '2026-09-30');
    $dayo = $this->tenantJson('POST', '/api/admin/hr/employees', $third, $this->staff)->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/admin/hr/employees/{$this->chidi['id']}/reactivate", [], $this->staff)->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');
    $this->tenantJson('DELETE', "/api/admin/hr/employees/{$dayo['id']}", [], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/hr/employees/{$this->chidi['id']}/reactivate", [], $this->staff)->assertOk()->assertJsonPath('data.termination_date', null);

    // Sales starts at 08:00; everyone else at 09:00 with ten minutes' grace.
    $this->tenantJson('PUT', '/api/admin/hr/settings', ['expected_clock_in_time' => '09:00', 'late_grace_minutes' => 10], $this->staff)->assertOk()
        ->assertJsonPath('data.expected_clock_out_time', '17:00');
    $this->tenantJson('PUT', "/api/admin/hr/departments/{$this->sales['id']}/settings", ['expected_clock_in_time' => '08:00'], $this->staff)->assertOk()
        ->assertJsonPath('data.late_grace_minutes', 10)->assertJsonPath('data.is_default', false);

    // 08:20: Bola (Sales) is late; Chidi (default rules) is on time, clocked in by the owner at the kiosk.
    $this->travelTo(lagos('08:20'));
    hrTokens();
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', [], $this->bolaAuth)->assertCreated()->assertJsonPath('data.is_late', true)
        ->assertJsonPath('data.work_date', lagos('08:20')->toDateString());
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', [], $this->bolaAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'already_clocked_in');
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', ['employee_id' => $this->chidi['id']], $this->bolaAuth)->assertForbidden()
        ->assertJsonPath('meta.details.permission', 'hr.attendance.record');
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'no_linked_employee');
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-in', ['employee_id' => $this->chidi['id']], $this->staff)->assertCreated()->assertJsonPath('data.is_late', false);

    $this->travelTo(lagos('16:00'));
    hrTokens();
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-out', [], $this->bolaAuth)->assertOk()->assertJsonPath('data.is_early_leave', true);
    $this->tenantJson('POST', '/api/admin/hr/attendance/clock-out', [], $this->bolaAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'already_clocked_out');
    $summary = collect($this->tenantJson('GET', '/api/admin/hr/attendance/summary', [], $this->staff)->assertOk()->json('data.employees'))->keyBy('employee_id');
    expect($summary[$this->bolaEmployee['id']])->toMatchArray(['days_present' => 1, 'late' => 1, 'early_leave' => 1])
        ->and($summary[$this->chidi['id']])->toMatchArray(['days_present' => 1, 'late' => 0]);

    // Documents: private files, with expiry where the type needs it.
    $permit = $this->tenantJson('POST', '/api/admin/hr/document-types', ['name' => 'Work permit', 'requires_expiry_date' => true], $this->staff)->assertCreated()->json('data');
    $file = UploadedFile::fake()->create('permit.pdf', 40, 'application/pdf');
    $this->tenantJson('POST', "/api/admin/hr/employees/{$this->chidi['id']}/documents", ['file' => $file, 'document_type_id' => $permit['id']], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors('expiry_date');
    $document = $this->tenantJson('POST', "/api/admin/hr/employees/{$this->chidi['id']}/documents", ['file' => $file, 'document_type_id' => $permit['id'],
        'expiry_date' => today()->addDays(10)->toDateString()], $this->staff)->assertCreated()->assertJsonPath('data.file.name', 'permit.pdf')->json('data');
    $this->tenantJson('GET', '/api/admin/hr/documents/expiring?within_days=30', [], $this->staff)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_name', 'Chidi Obi');
    $this->tenantJson('GET', '/api/admin/hr/documents/expiring?within_days=5', [], $this->staff)->assertOk()->assertJsonCount(0, 'data');
    $this->withHeaders($this->staff)->get('http://'.$this->tenantHost().'/api/admin/hr/employees/'.$this->chidi['id'].'/documents/'.$document['id'].'/download')->assertOk()
        ->assertDownload('permit.pdf');
    $this->tenantJson('GET', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/documents/{$document['id']}/download", [], $this->staff)->assertNotFound();
    $this->tenantJson('DELETE', "/api/admin/hr/document-types/{$permit['id']}", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'document_type_in_use');

    // The dashboard section, the employees strip and the lookups.
    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/hr?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($section)->toMatchArray(['headcount' => 2, 'on_leave_today' => 0, 'pending_leave_requests' => 0])->not->toHaveKey('open_postings');
    $strip = collect($this->tenantJson('GET', '/api/admin/hr/employees/metrics?range=this_year&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($strip)->toMatchArray(['headcount' => 2, 'joined' => 2]);
    $this->tenantJson('GET', '/api/admin/lookups/departments', [], $this->staff)->assertOk()->assertJsonPath('data.0.label', 'Sales');
    $this->tenantJson('GET', '/api/admin/lookups/employment-types', [], $this->staff)->assertOk()->assertJsonCount(4, 'data');
});

it('books leave against the balance, through staff or an approval workflow, and lets the requester cancel', function (): void {
    $annual = $this->tenantJson('POST', '/api/admin/hr/leave-types', ['name' => 'Annual', 'days_per_year' => 10], $this->staff)->assertCreated()->json('data');
    $monday = CarbonImmutable::parse('next monday');

    // Bola asks for Monday to Wednesday: three working days.
    $first = $this->tenantJson('POST', '/api/admin/hr/leave-requests', ['leave_type_id' => $annual['id'], 'start_date' => $monday->toDateString(),
        'end_date' => $monday->addDays(2)->toDateString(), 'reason' => 'Family'], $this->bolaAuth)->assertCreated()
        ->assertJsonPath('data.days', '3.0')->assertJsonPath('data.status', 'pending')->json('data');
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'hr.leave_request_submitted' && str_contains($n->body, 'Bola Ade'));

    // Overlaps, and more than is left once pending requests count, are refused.
    $this->tenantJson('POST', '/api/admin/hr/leave-requests', ['leave_type_id' => $annual['id'], 'start_date' => $monday->addDays(2)->toDateString(),
        'end_date' => $monday->addDays(3)->toDateString()], $this->bolaAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'leave_overlap');
    $this->tenantJson('POST', '/api/admin/hr/leave-requests', ['leave_type_id' => $annual['id'], 'start_date' => $monday->addWeeks(2)->toDateString(),
        'end_date' => $monday->addWeeks(2)->addDays(9)->toDateString()], $this->bolaAuth)->assertStatus(422)
        ->assertJsonPath('meta.error_code', 'insufficient_leave_balance')->assertJsonPath('meta.details.available', '7.0');
    $this->tenantJson('POST', "/api/admin/hr/leave-requests/{$first['id']}/approve", [], $this->bolaAuth)->assertForbidden();

    $this->tenantJson('POST', "/api/admin/hr/leave-requests/{$first['id']}/approve", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'approved');
    Notification::assertSentTo($this->bola, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'hr.leave_request_approved');
    $this->tenantJson('GET', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/leave-balances?year={$monday->year}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.0.used_days', '3.0')->assertJsonPath('data.0.remaining_days', '7.0');

    // A half day, rejected with a reason.
    $half = $this->tenantJson('POST', '/api/admin/hr/leave-requests', ['leave_type_id' => $annual['id'], 'start_date' => $monday->addWeek()->toDateString(),
        'end_date' => $monday->addWeek()->toDateString(), 'days' => 0.5], $this->bolaAuth)->assertCreated()->assertJsonPath('data.days', '0.5')->json('data');
    $this->tenantJson('POST', "/api/admin/hr/leave-requests/{$half['id']}/reject", ['reason' => 'Stock take that day'], $this->staff)->assertOk()->assertJsonPath('data.status', 'rejected');
    Notification::assertSentTo($this->bola, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'hr.leave_request_rejected' && str_contains($n->body, 'Stock take'));

    // The owner books leave for Chidi; Bola may not cancel it, the owner may.
    $forChidi = $this->tenantJson('POST', '/api/admin/hr/leave-requests', ['employee_id' => $this->chidi['id'], 'leave_type_id' => $annual['id'],
        'start_date' => $monday->toDateString(), 'end_date' => $monday->toDateString()], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/admin/hr/leave-requests/{$forChidi['id']}/cancel", [], $this->bolaAuth)->assertForbidden()
        ->assertJsonPath('meta.details.permission', 'hr.leave-requests.cancel');
    $this->tenantJson('POST', "/api/admin/hr/leave-requests/{$forChidi['id']}/cancel", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'cancelled');

    // Five days or more go through the approval workflow.
    $this->tenantJson('POST', '/api/admin/modules/approval_workflows/enable', [], $this->staff)->assertOk();
    $this->tenantJson('POST', '/api/admin/approval-workflows', ['name' => 'Long leave', 'module_key' => 'leave_request', 'trigger_conditions' => ['min_days' => 5],
        'steps' => [['name' => 'Owner', 'approvers' => [['approver_type' => 'user', 'user_id' => $this->owner->id]]]]], $this->staff)->assertCreated();
    $long = $this->tenantJson('POST', '/api/admin/hr/leave-requests', ['leave_type_id' => $annual['id'], 'start_date' => $monday->addWeeks(3)->toDateString(),
        'end_date' => $monday->addWeeks(3)->addDays(4)->toDateString()], $this->bolaAuth)->assertCreated()->assertJsonPath('data.days', '5.0')->json('data');
    $this->tenantJson('POST', "/api/admin/hr/leave-requests/{$long['id']}/approve", [], $this->staff)->assertStatus(409)->assertJsonPath('meta.error_code', 'approval_pending');

    tenancy()->initialize($this->tenant);
    $approval = ApprovalRequest::query()->where('approvable_type', 'hr_leave_request')->where('approvable_id', $long['id'])->firstOrFail();
    $this->tenantJson('POST', "/api/admin/approvals/{$approval->id}/approve", [], $this->staff)->assertOk();
    $this->tenantJson('GET', "/api/admin/hr/leave-requests?employee_id={$this->bolaEmployee['id']}&status=approved", [], $this->staff)->assertOk()->assertJsonCount(2, 'data');
    $this->tenantJson('GET', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/leave-balances?year={$monday->year}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.0.used_days', '8.0');
});

it('runs payroll from salary history to an itemised, finalized and paid run that posts once', function (): void {
    $this->tenantJson('POST', '/api/admin/modules/accounting/enable', [], $this->staff)->assertOk();
    $year = now()->year;
    $this->tenantJson('POST', '/api/admin/accounting/fiscal-years', ['name' => "FY{$year}", 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31"], $this->staff)->assertCreated();
    $this->tenantJson('POST', '/api/admin/hr/payroll-runs', ['period_start' => '2026-01-01', 'period_end' => '2026-01-31'], $this->staff)
        ->assertForbidden()->assertJsonPath('meta.error_code', 'module_disabled');
    $this->tenantJson('POST', '/api/admin/modules/hr_payroll/enable', [], $this->staff)->assertOk();

    $month = CarbonImmutable::today()->startOfMonth();
    $bola = "/api/admin/hr/employees/{$this->bolaEmployee['id']}/salary";
    $this->tenantJson('POST', $bola, ['base_salary' => '300000', 'effective_from' => $month->subMonth()->toDateString()], $this->staff)->assertCreated();
    $this->tenantJson('POST', $bola, ['base_salary' => '350000', 'effective_from' => $month->toDateString()], $this->staff)->assertCreated()->assertJsonPath('data.currency_code', 'NGN');
    $this->tenantJson('POST', $bola, ['base_salary' => '1', 'effective_from' => $month->subMonths(2)->toDateString()], $this->staff)->assertStatus(422)
        ->assertJsonPath('meta.error_code', 'salary_effective_date_invalid');
    $this->tenantJson('GET', $bola, [], $this->staff)->assertOk()->assertJsonPath('data.current.base_salary', '350000.0000')
        ->assertJsonPath('data.history.1.effective_to', $month->subDay()->toDateString());
    $this->tenantJson('POST', "/api/admin/hr/employees/{$this->chidi['id']}/salary", ['base_salary' => '200000', 'effective_from' => $month->subMonth()->toDateString()], $this->staff)->assertCreated();

    $period = ['period_start' => $month->toDateString(), 'period_end' => $month->endOfMonth()->toDateString()];
    $run = $this->tenantJson('POST', '/api/admin/hr/payroll-runs', $period, $this->staff)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
    $this->tenantJson('POST', '/api/admin/hr/payroll-runs', ['period_start' => $month->addDays(10)->toDateString(), 'period_end' => $month->addMonth()->toDateString()], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'payroll_period_overlap');

    $items = collect($this->tenantJson('POST', "/api/admin/hr/payroll-runs/{$run['id']}/generate-items", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'processing')->json('data.items'))->keyBy('employee.id');
    expect($items[$this->bolaEmployee['id']]['base_salary'])->toBe('350000.0000');

    // Bola: housing, 10% PAYE of base, pension. Chidi: a deduction above gross is refused.
    $bolaItem = $items[$this->bolaEmployee['id']]['id'];
    $this->tenantJson('POST', "/api/admin/hr/payroll-items/{$bolaItem}/lines", ['type' => 'allowance', 'label' => 'Housing', 'amount' => '50000'], $this->staff)->assertCreated();
    $this->tenantJson('POST', "/api/admin/hr/payroll-items/{$bolaItem}/lines", ['type' => 'tax', 'label' => 'PAYE', 'amount' => '10', 'is_percentage' => true], $this->staff)
        ->assertCreated()->assertJsonPath('data.lines.1.amount', '35000.0000');
    $pension = $this->tenantJson('POST', "/api/admin/hr/payroll-items/{$bolaItem}/lines", ['type' => 'deduction', 'label' => 'Pension', 'amount' => '20000'], $this->staff)
        ->assertCreated()->assertJsonPath('data.gross_pay', '400000.0000')->assertJsonPath('data.net_pay', '345000.0000')->json('data.lines.2.id');
    $this->tenantJson('POST', "/api/admin/hr/payroll-items/{$items[$this->chidi['id']]['id']}/lines", ['type' => 'deduction', 'label' => 'Advance', 'amount' => '250000'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'negative_net_pay');
    $this->tenantJson('DELETE', "/api/admin/hr/payroll-item-lines/{$pension}", [], $this->staff)->assertOk()->assertJsonPath('data.net_pay', '365000.0000');
    $this->tenantJson('POST', "/api/admin/hr/payroll-items/{$bolaItem}/lines", ['type' => 'deduction', 'label' => 'Pension', 'amount' => '20000'], $this->staff)->assertCreated();

    $this->tenantJson('GET', "/api/admin/hr/payroll-runs/{$run['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.total_gross', '600000.0000')->assertJsonPath('data.total_deductions', '55000.0000')->assertJsonPath('data.total_net', '545000.0000');
    $paid = [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()];
    $this->tenantJson('POST', "/api/admin/hr/payroll-items/{$bolaItem}/mark-paid", [], $paid)->assertStatus(422)->assertJsonPath('meta.error_code', 'payroll_run_not_finalized');

    // Finalized: locked for good; payroll cannot be switched off until it is paid.
    $this->tenantJson('POST', "/api/admin/hr/payroll-runs/{$run['id']}/finalize", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'finalized');
    $this->tenantJson('POST', "/api/admin/hr/payroll-items/{$bolaItem}/lines", ['type' => 'bonus', 'label' => 'Late', 'amount' => '1'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'payroll_run_locked');
    $this->tenantJson('POST', '/api/admin/modules/hr_payroll/disable', [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'module_disable_blocked');

    $this->tenantJson('POST', "/api/admin/hr/payroll-items/{$bolaItem}/mark-paid", [], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])->assertOk()
        ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.period.status', 'finalized');
    $this->tenantJson('POST', "/api/admin/hr/payroll-runs/{$run['id']}/mark-paid", [], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])->assertOk()
        ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.items.1.status', 'paid');
    $this->tenantJson('POST', "/api/admin/hr/payroll-runs/{$run['id']}/mark-paid", [], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])->assertStatus(422);

    // One journal entry: Dr salaries 600,000; Cr cash 545,000; Cr payroll liabilities 55,000.
    tenancy()->initialize($this->tenant);
    $journal = DB::connection('tenant')->table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')->where('e.posting_key', 'like', 'payroll:%')
        ->selectRaw("CONCAT(a.system_key, ':', l.type) as k, SUM(l.amount) as amount")->groupBy('a.system_key', 'l.type')->pluck('amount', 'k')->map(fn ($a): string => (string) $a)->all();
    expect($journal)->toEqual(['salaries_wages:debit' => '600000.0000', 'cash_bank:credit' => '545000.0000', 'payroll_liabilities:credit' => '55000.0000']);

    $this->tenantJson('GET', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/payslips/{$run['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.net_pay', '345000.0000')->assertJsonPath('data.tax_amount', '35000.0000')->assertJsonCount(3, 'data.lines');
    $this->tenantJson('GET', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/payroll-history", [], $this->staff)->assertOk()->assertJsonCount(1, 'data');

    // Switched off: history stays readable, nothing new.
    $this->tenantJson('POST', '/api/admin/modules/hr_payroll/disable', [], $this->staff)->assertOk();
    $this->tenantJson('GET', '/api/admin/hr/payroll-runs', [], $this->staff)->assertOk()->assertJsonCount(1, 'data');
    $this->tenantJson('POST', '/api/admin/hr/payroll-runs', ['period_start' => '2020-01-01', 'period_end' => '2020-01-31'], $this->staff)->assertForbidden();
});

it('scores appraisals against a weighted template and lets the employee acknowledge them', function (): void {
    $this->tenantJson('POST', '/api/admin/hr/appraisal-templates', ['name' => 'Annual review', 'criteria' => [
        ['label' => 'Quality', 'weight' => 60, 'max_score' => 5], ['label' => 'Teamwork', 'weight' => 30, 'max_score' => 10],
    ]], $this->staff)->assertCreated()->assertJsonPath('data.total_weight', '90.0000');
    $template = $this->tenantJson('GET', '/api/admin/hr/appraisal-templates', [], $this->staff)->assertOk()->json('data.0');
    $period = ['appraisal_template_id' => $template['id'], 'period_start' => '2026-01-01', 'period_end' => '2026-06-30'];

    // Weights must reach 100 before the template is used.
    $this->tenantJson('POST', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/appraisals", $period, $this->staff)->assertStatus(422)
        ->assertJsonPath('meta.error_code', 'appraisal_template_incomplete');
    $template = $this->tenantJson('POST', "/api/admin/hr/appraisal-templates/{$template['id']}/criteria", ['label' => 'Punctuality', 'weight' => 10, 'max_score' => 4], $this->staff)
        ->assertCreated()->assertJsonPath('data.total_weight', '100.0000')->json('data');
    [$quality, $teamwork, $punctuality] = array_column($template['criteria'], 'id');

    $appraisal = $this->tenantJson('POST', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/appraisals", $period, $this->staff)->assertCreated()
        ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.reviewer.id', $this->owner->id)->json('data');
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$appraisal['id']}/scores", ['scores' => [['criterion_id' => $quality, 'score' => 6]]], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors('score');
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$appraisal['id']}/scores", ['scores' => [['criterion_id' => $quality, 'score' => 4], ['criterion_id' => $teamwork, 'score' => 7]]], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$appraisal['id']}/submit", [], $this->staff)->assertStatus(422)
        ->assertJsonPath('meta.error_code', 'appraisal_incomplete')->assertJsonPath('meta.details.missing_criterion_ids', [$punctuality]);
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$appraisal['id']}/scores", ['scores' => [['criterion_id' => $punctuality, 'score' => 3, 'comments' => 'Mostly on time']]], $this->staff)->assertOk();

    // 4/5 × 60 + 7/10 × 30 + 3/4 × 10 = 48 + 21 + 7.5.
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$appraisal['id']}/submit", ['overall_comments' => 'Solid year'], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.overall_score', '76.50');
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$appraisal['id']}/scores", ['scores' => [['criterion_id' => $quality, 'score' => 5]]], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'appraisal_submitted');
    $this->tenantJson('DELETE', "/api/admin/hr/appraisal-templates/{$template['id']}", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'appraisal_template_in_use');

    // Bola acknowledges her own; a standalone employee's needs the permission (the owner has it).
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$appraisal['id']}/acknowledge", [], $this->bolaAuth)->assertOk()->assertJsonPath('data.status', 'acknowledged');
    $chidis = $this->tenantJson('POST', "/api/admin/hr/employees/{$this->chidi['id']}/appraisals", $period, $this->staff)->assertCreated()->json('data');
    foreach ([$quality => 5, $teamwork => 10, $punctuality => 4] as $criterion => $score) {
        $this->tenantJson('POST', "/api/admin/hr/appraisals/{$chidis['id']}/scores", ['scores' => [['criterion_id' => $criterion, 'score' => $score]]], $this->staff)->assertOk();
    }
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$chidis['id']}/submit", [], $this->staff)->assertOk()->assertJsonPath('data.overall_score', '100.00');
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$chidis['id']}/acknowledge", [], $this->bolaAuth)->assertForbidden();
    $this->tenantJson('POST', "/api/admin/hr/appraisals/{$chidis['id']}/acknowledge", [], $this->staff)->assertOk();
    $this->tenantJson('GET', "/api/admin/hr/employees/{$this->bolaEmployee['id']}/appraisals", [], $this->staff)->assertOk()->assertJsonPath('data.0.criteria.2.comments', 'Mostly on time');
});

it('publishes jobs on the careers page, takes applications with a résumé and turns a hire into an employee', function (): void {
    $this->tenantJson('GET', '/api/careers/jobs')->assertForbidden();
    $this->tenantJson('POST', '/api/admin/modules/hr_recruitment/enable', [], $this->staff)->assertOk();

    $posting = $this->tenantJson('POST', '/api/admin/hr/job-postings', ['title' => 'Store Manager', 'department_id' => $this->sales['id'], 'description' => 'Run the Lekki store.',
        'location' => 'Lagos', 'employment_type' => 'full_time', 'application_deadline' => today()->addDays(14)->toDateString()], $this->staff)
        ->assertCreated()->assertJsonPath('data.slug', 'store-manager')->assertJsonPath('data.status', 'draft')->json('data');
    $this->tenantJson('GET', '/api/careers/jobs/store-manager')->assertNotFound();
    $this->tenantJson('POST', "/api/admin/hr/job-postings/{$posting['id']}/publish", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'open');
    $this->tenantJson('GET', '/api/careers/jobs?location=Lagos')->assertOk()->assertJsonPath('data.0.slug', 'store-manager')->assertJsonMissingPath('data.0.status');

    $apply = fn (array $body) => $this->tenantJson('POST', '/api/careers/jobs/store-manager/apply', [
        'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'Ngozi@Mail.test', 'resume' => UploadedFile::fake()->create('cv.pdf', 60, 'application/pdf'), ...$body,
    ]);
    $apply(['cover_letter' => 'I ran a store for five years.'])->assertCreated()->assertJsonPath('data.job.slug', 'store-manager')->assertJsonMissingPath('data.status');
    $apply([])->assertStatus(422)->assertJsonPath('meta.error_code', 'already_applied');

    // Someone else using Ngozi's address cannot change her record.
    $second = $this->tenantJson('POST', '/api/admin/hr/job-postings', ['title' => 'Cashier', 'description' => 'Tills.', 'employment_type' => 'part_time'], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/admin/hr/job-postings/{$second['id']}/publish", [], $this->staff)->assertOk();
    $this->tenantJson('POST', '/api/careers/jobs/cashier/apply', ['first_name' => 'Impostor', 'last_name' => 'X', 'email' => 'ngozi@mail.test',
        'resume' => UploadedFile::fake()->create('other.pdf', 10, 'application/pdf')])->assertCreated();
    tenancy()->initialize($this->tenant);
    expect(HrCandidate::query()->where('email', 'ngozi@mail.test')->firstOrFail()->first_name)->toBe('Ngozi')
        ->and(HrCandidate::query()->count())->toBe(1);

    // Staff work the pipeline; notes stay internal.
    $application = $this->tenantJson('GET', "/api/admin/hr/job-postings/{$posting['id']}/applications", [], $this->staff)->assertOk()
        ->assertJsonPath('data.0.candidate.resume.name', 'cv.pdf')->json('data.0');
    $this->tenantJson('POST', "/api/admin/hr/applications/{$application['id']}/notes", ['note' => 'Strong references'], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/hr/applications/{$application['id']}/convert-to-employee", ['hire_date' => '2026-11-01'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'application_not_hired');
    $this->tenantJson('PATCH', "/api/admin/hr/applications/{$application['id']}/status", ['status' => 'hired'], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'hired')->assertJson(fn ($json) => $json->where('data.notes', fn (string $notes): bool => str_contains($notes, 'Strong references'))->etc());
    $this->withHeaders($this->staff)->get('http://'.$this->tenantHost()."/api/admin/hr/applications/{$application['id']}/resume")->assertOk()->assertDownload('cv.pdf');

    $employee = $this->tenantJson('POST', "/api/admin/hr/applications/{$application['id']}/convert-to-employee", ['hire_date' => '2026-11-01'], $this->staff)
        ->assertCreated()->assertJsonPath('data.name', 'Ngozi Eze')->assertJsonPath('data.job_title', 'Store Manager')
        ->assertJsonPath('data.department.id', $this->sales['id'])->assertJsonPath('data.employment_type', 'full_time')->json('data');
    $this->tenantJson('POST', "/api/admin/hr/applications/{$application['id']}/convert-to-employee", ['hire_date' => '2026-11-01'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'application_converted');
    $this->tenantJson('GET', "/api/admin/hr/employees/{$employee['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.email', 'ngozi@mail.test');

    // Closed: no more applications; the dashboard counts recruitment now.
    $this->tenantJson('POST', "/api/admin/hr/job-postings/{$posting['id']}/close", [], $this->staff)->assertOk();
    $apply(['email' => 'late@mail.test'])->assertNotFound();
    $this->tenantJson('DELETE', "/api/admin/hr/job-postings/{$posting['id']}", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'job_posting_not_draft');
    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/hr?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($section)->toMatchArray(['headcount' => 3, 'open_postings' => 1, 'applications' => 2]);
});
