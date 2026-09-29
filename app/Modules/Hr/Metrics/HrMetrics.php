<?php

declare(strict_types=1);

namespace App\Modules\Hr\Metrics;

use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrJobPosting;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The HR section (spec §44.3): headcount (active employees), on leave
 * today (approved leave covering today), pending leave requests, and, with
 * hr_recruitment, open postings and applications received in the range.
 */
final readonly class HrMetrics
{
    public function __construct(private FeatureAccessService $features) {}

    public function hr(DateRange $range, MetricsScope $scope): SectionResult
    {
        $kpis = [
            KpiValue::count('headcount', 'Headcount', $this->headcount(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('on_leave_today', 'On leave today', $this->onLeaveToday(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('pending_leave_requests', 'Pending leave requests', DB::connection('tenant')->table('hr_leave_requests')
                ->where('status', HrLeaveRequest::PENDING)->count(), $range, null, KpiValue::DOWN_IS_GOOD),
        ];

        $tenant = tenant();

        if ($tenant instanceof Tenant && $this->features->state($tenant, 'hr_recruitment') === ModuleState::Enabled) {
            $comparison = $range->comparison();
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
        $joined = DB::connection('tenant')->table('hr_employees')->whereNull('deleted_at')
            ->whereBetween('hire_date', [$range->startUtc()->setTimezone($range->zone())->toDateString(), $range->endUtc()->setTimezone($range->zone())->toDateString()])->count();

        return [
            KpiValue::count('headcount', 'Headcount', $this->headcount(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('on_leave_today', 'On leave today', $this->onLeaveToday(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('joined', 'Joined', $joined, $range, null, KpiValue::UP_IS_GOOD),
        ];
    }

    private function headcount(): int
    {
        return DB::connection('tenant')->table('hr_employees')->whereNull('deleted_at')->where('status', HrEmployee::ACTIVE)->count();
    }

    private function onLeaveToday(): int
    {
        $today = today()->toDateString();

        return DB::connection('tenant')->table('hr_leave_requests')->where('status', HrLeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->distinct()->count('employee_id');
    }

    private function applications(DateRange $range): int
    {
        return DB::connection('tenant')->table('hr_job_applications')->whereBetween('applied_at', [$range->startUtc(), $range->endUtc()])->count();
    }
}
