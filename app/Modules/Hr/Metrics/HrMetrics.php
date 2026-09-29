<?php

declare(strict_types=1);

namespace App\Modules\Hr\Metrics;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrJobPosting;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Models\HrPayrollItemLine;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The HR section (spec §44.3):
 * - people: headcount (active employees), on leave today (approved leave
 *   covering today) and pending leave requests;
 * - attendance (§58.3a): clocked in now, rostered today, absent today
 *   (rostered, shift started, not clocked in, not on leave), late arrivals
 *   in the range, pending overtime and approved overtime minutes;
 * - with hr_payroll: overtime pay in runs starting in the range;
 * - with hr_recruitment: open postings and applications in the range.
 * "Today" is the store's day in its timezone.
 */
final readonly class HrMetrics
{
    public function __construct(
        private FeatureAccessService $features,
        private CurrencyService $currencies,
    ) {}

    public function hr(DateRange $range, MetricsScope $scope): SectionResult
    {
        $now = CarbonImmutable::now($range->zone());
        $comparison = $range->comparison();
        $tenant = tenant();

        $kpis = [
            KpiValue::count('headcount', 'Headcount', $this->headcount(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('on_leave_today', 'On leave today', $this->onLeaveToday($now), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('pending_leave_requests', 'Pending leave requests', DB::connection('tenant')->table('hr_leave_requests')
                ->where('status', HrLeaveRequest::PENDING)->count(), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('clocked_in_now', 'Clocked in now', $this->clockedInNow($now), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('rostered_today', 'Rostered today', $this->rosteredToday($now), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('absent_today', 'Absent today', $this->absentToday($now), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('late_arrivals', 'Late arrivals', $this->lateArrivals($range), $range,
                $comparison === null ? null : $this->lateArrivals($comparison), KpiValue::DOWN_IS_GOOD),
            KpiValue::count('pending_overtime', 'Overtime awaiting approval', DB::connection('tenant')->table('hr_attendance')
                ->where('overtime_status', HrAttendance::OVERTIME_PENDING)->count(), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('overtime_approved_minutes', 'Approved overtime (minutes)', $this->approvedOvertimeMinutes($range), $range,
                $comparison === null ? null : $this->approvedOvertimeMinutes($comparison), KpiValue::NEUTRAL),
        ];

        if ($tenant instanceof Tenant && $this->features->state($tenant, 'hr_payroll') === ModuleState::Enabled) {
            $kpis[] = KpiValue::money('overtime_pay', 'Overtime pay', $this->overtimePay($range), $this->currencies->baseCurrency(), $range,
                $comparison === null ? null : $this->overtimePay($comparison), KpiValue::DOWN_IS_GOOD);
        }

        if ($tenant instanceof Tenant && $this->features->state($tenant, 'hr_recruitment') === ModuleState::Enabled) {
            $kpis[] = KpiValue::count('open_postings', 'Open postings', DB::connection('tenant')->table('hr_job_postings')
                ->where('status', HrJobPosting::OPEN)->count(), $range, null, KpiValue::NEUTRAL);
            $kpis[] = KpiValue::count('applications', 'Applications', $this->applications($range), $range,
                $comparison === null ? null : $this->applications($comparison), KpiValue::UP_IS_GOOD);
        }

        return new SectionResult(kpis: $kpis);
    }

    /**
     * The employees list strip (§44.4): headcount, on leave today, joined
     * in the range (hire date).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        [$from, $to] = $this->localDates($range);
        $joined = DB::connection('tenant')->table('hr_employees')->whereNull('deleted_at')->whereBetween('hire_date', [$from, $to])->count();

        return [
            KpiValue::count('headcount', 'Headcount', $this->headcount(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('on_leave_today', 'On leave today', $this->onLeaveToday(CarbonImmutable::now($range->zone())), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('joined', 'Joined', $joined, $range, null, KpiValue::UP_IS_GOOD),
        ];
    }

    private function headcount(): int
    {
        return DB::connection('tenant')->table('hr_employees')->whereNull('deleted_at')->where('status', HrEmployee::ACTIVE)->count();
    }

    private function onLeaveToday(CarbonImmutable $now): int
    {
        $today = $now->toDateString();

        return DB::connection('tenant')->table('hr_leave_requests')->where('status', HrLeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->distinct()->count('employee_id');
    }

    /**
     * Open rows from the last day: a night shift that began yesterday counts.
     */
    private function clockedInNow(CarbonImmutable $now): int
    {
        return DB::connection('tenant')->table('hr_attendance')->whereNull('clock_out_at')
            ->where('clock_in_at', '>=', $now->utc()->subDay())->distinct()->count('employee_id');
    }

    private function rosteredToday(CarbonImmutable $now): int
    {
        return DB::connection('tenant')->table('hr_shift_assignments as a')
            ->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->whereNull('e.deleted_at')->where('e.status', HrEmployee::ACTIVE)
            ->whereDate('a.work_date', $now->toDateString())->count();
    }

    /**
     * Rostered today, the shift has started, no attendance for that work
     * date and no approved leave covering today.
     */
    private function absentToday(CarbonImmutable $now): int
    {
        $today = $now->toDateString();

        return DB::connection('tenant')->table('hr_shift_assignments as a')
            ->join('hr_shifts as s', 's.id', '=', 'a.shift_id')
            ->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->whereNull('e.deleted_at')->where('e.status', HrEmployee::ACTIVE)
            ->whereDate('a.work_date', $today)
            ->where('s.start_time', '<=', $now->format('H:i:s'))
            ->whereNotExists(static fn ($q) => $q->from('hr_attendance as t')->whereColumn('t.employee_id', 'a.employee_id')->whereColumn('t.work_date', 'a.work_date'))
            ->whereNotExists(static fn ($q) => $q->from('hr_leave_requests as l')->whereColumn('l.employee_id', 'a.employee_id')
                ->where('l.status', HrLeaveRequest::APPROVED)->whereDate('l.start_date', '<=', $today)->whereDate('l.end_date', '>=', $today))
            ->count();
    }

    private function lateArrivals(DateRange $range): int
    {
        [$from, $to] = $this->localDates($range);

        return DB::connection('tenant')->table('hr_attendance')->whereBetween('work_date', [$from, $to])->where('is_late', true)->count();
    }

    private function approvedOvertimeMinutes(DateRange $range): int
    {
        [$from, $to] = $this->localDates($range);

        return (int) DB::connection('tenant')->table('hr_attendance')->whereBetween('work_date', [$from, $to])
            ->where('overtime_status', HrAttendance::OVERTIME_APPROVED)->sum('overtime_approved_minutes');
    }

    /**
     * Overtime lines of runs past draft whose period starts in the range, so
     * a month-to-date range already shows the current month's run.
     */
    private function overtimePay(DateRange $range): string
    {
        [$from, $to] = $this->localDates($range);

        return Money::normalize((string) DB::connection('tenant')->table('hr_payroll_item_lines as l')
            ->join('hr_payroll_items as i', 'i.id', '=', 'l.payroll_item_id')
            ->join('hr_payroll_runs as r', 'r.id', '=', 'i.payroll_run_id')
            ->where('l.type', HrPayrollItemLine::OVERTIME)
            ->where('r.status', '!=', HrPayrollRun::DRAFT)
            ->whereBetween('r.period_start', [$from, $to])
            ->sum('l.amount'));
    }

    private function applications(DateRange $range): int
    {
        return DB::connection('tenant')->table('hr_job_applications')->whereBetween('applied_at', [$range->startUtc(), $range->endUtc()])->count();
    }

    /**
     * The range as local calendar dates, for date columns.
     *
     * @return array{0: string, 1: string}
     */
    private function localDates(DateRange $range): array
    {
        return [$range->startUtc()->setTimezone($range->zone())->toDateString(), $range->endUtc()->setTimezone($range->zone())->toDateString()];
    }
}
