<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrPayrollItem;
use App\Modules\Hr\Models\HrPayrollItemLine;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Hr\Models\HrSalaryStructure;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Salaries and payroll runs (spec §58.5). Amounts are in the base currency
 * (Assumption A-50). Every change to a run happens under its row lock; a
 * finalized run is immutable (§5.2); a run posts to accounting exactly once,
 * when it becomes paid.
 */
final readonly class HrPayrollService
{
    public function __construct(
        private CurrencyService $currencies,
        private AccountingOutbox $outbox,
    ) {}

    /**
     * Closes the current structure the day before the new one starts;
     * history is never rewritten, so the new date must be later.
     *
     * @param  array<string, mixed>  $data  base_salary, effective_from
     */
    public function createSalaryStructure(HrEmployee $employee, array $data): HrSalaryStructure
    {
        $validated = Validator::make($data, [
            'base_salary' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999999'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($employee, $validated): HrSalaryStructure {
            HrEmployee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $from = CarbonImmutable::parse($validated['effective_from']);
            /** @var HrSalaryStructure|null $latest */
            $latest = HrSalaryStructure::query()->where('employee_id', $employee->id)->orderByDesc('effective_from')->first();

            if ($latest !== null && ! $from->greaterThan($latest->effective_from)) {
                throw ApiException::unprocessable('salary_effective_date_invalid', 'A new salary must start after '.$latest->effective_from->toDateString().'.');
            }

            if ($latest !== null && ($latest->effective_to === null || $latest->effective_to->greaterThanOrEqualTo($from))) {
                $latest->forceFill(['effective_to' => $from->subDay()->toDateString()])->save();
            }

            $structure = new HrSalaryStructure;
            $structure->forceFill([
                'employee_id' => $employee->id,
                'base_salary' => Money::normalize((string) $validated['base_salary']),
                'currency_code' => $this->currencies->baseCurrency(),
                'effective_from' => $from->toDateString(),
            ])->save();

            return $structure;
        });
    }

    public function getCurrentSalary(HrEmployee $employee, ?CarbonImmutable $on = null): ?HrSalaryStructure
    {
        $on ??= CarbonImmutable::today();

        return HrSalaryStructure::query()->where('employee_id', $employee->id)->whereDate('effective_from', '<=', $on)
            ->where(static fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')->first();
    }

    /**
     * @return Collection<int, HrSalaryStructure>
     */
    public function getSalaryHistory(HrEmployee $employee): Collection
    {
        return HrSalaryStructure::query()->where('employee_id', $employee->id)->orderByDesc('effective_from')->get();
    }

    /**
     * A period that overlaps another run is refused, so nobody is paid twice for the same days.
     */
    public function createPayrollRun(string $periodStart, string $periodEnd, ?User $by = null): HrPayrollRun
    {
        Validator::make(['period_start' => $periodStart, 'period_end' => $periodEnd], [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ])->validate();

        return DB::connection('tenant')->transaction(static function () use ($periodStart, $periodEnd, $by): HrPayrollRun {
            // Serialises run creation on a counter row, so the overlap check and the insert are one step.
            DB::connection('tenant')->table('sequences')->insertOrIgnore(['name' => 'hr_payroll_run', 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::connection('tenant')->table('sequences')->where('name', 'hr_payroll_run')->lockForUpdate()->first();

            if (HrPayrollRun::query()->whereDate('period_start', '<=', $periodEnd)->whereDate('period_end', '>=', $periodStart)->exists()) {
                throw ApiException::unprocessable('payroll_period_overlap', 'Another payroll run covers part of this period.');
            }

            $run = new HrPayrollRun;
            $run->forceFill([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => HrPayrollRun::DRAFT,
                'run_date' => today()->toDateString(),
                'created_by_user_id' => $by?->id,
            ])->save();

            return $run;
        });
    }

    /**
     * @param  array{status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, HrPayrollRun>
     */
    public function listPayrollRuns(array $filters = []): LengthAwarePaginator
    {
        return HrPayrollRun::query()->withCount('items')
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('period_start')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * draft → processing: one item per active employee with a salary in
     * force at the end of the period.
     */
    public function generatePayrollItemsForRun(HrPayrollRun $run): HrPayrollRun
    {
        return DB::connection('tenant')->transaction(function () use ($run): HrPayrollRun {
            $locked = $this->lockRun($run);

            if ($locked->status !== HrPayrollRun::DRAFT) {
                throw ApiException::invalidTransition($locked->status, HrPayrollRun::PROCESSING);
            }

            $created = 0;

            HrEmployee::query()->where('status', HrEmployee::ACTIVE)->orderBy('id')->get()
                ->each(function (HrEmployee $employee) use ($locked, &$created): void {
                    $salary = $this->getCurrentSalary($employee, CarbonImmutable::parse($locked->period_end));

                    if ($salary === null) {
                        return;
                    }

                    $item = new HrPayrollItem;
                    $item->forceFill([
                        'payroll_run_id' => $locked->id,
                        'employee_id' => $employee->id,
                        'base_salary' => (string) $salary->base_salary,
                        'gross_pay' => (string) $salary->base_salary,
                        'net_pay' => (string) $salary->base_salary,
                        'status' => HrPayrollItem::PENDING,
                    ])->save();
                    $created++;
                });

            if ($created === 0) {
                throw ApiException::unprocessable('no_payable_employees', 'No active employee has a salary for this period.');
            }

            $locked->forceFill(['status' => HrPayrollRun::PROCESSING])->save();
            $this->recalculateRun($locked);

            return $locked;
        });
    }

    /**
     * @return Collection<int, HrPayrollItem>
     */
    public function listPayrollItems(HrPayrollRun $run): Collection
    {
        return HrPayrollItem::query()->with(['employee.user:id,name', 'lines'])->where('payroll_run_id', $run->id)->orderBy('id')->get();
    }

    /**
     * A percentage line's amount is that share of the base salary, rounded
     * to the currency. Net pay can never go below zero.
     */
    public function addPayrollItemLine(HrPayrollItem $item, string $type, string $label, string $amount, bool $isPercentage = false): HrPayrollItemLine
    {
        Validator::make(['type' => $type, 'label' => $label, 'amount' => $amount], [
            'type' => ['required', Rule::in(HrPayrollItemLine::TYPES)],
            'label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', $isPercentage ? 'max:100' : 'max:99999999999999', 'decimal:0,4'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($item, $type, $label, $amount, $isPercentage): HrPayrollItemLine {
            [$run, $locked] = $this->lockEditable($item);
            $currency = $this->currencies->baseCurrency();
            $computed = $isPercentage
                ? Money::round(bcdiv(bcmul((string) $locked->base_salary, $amount, 10), '100', 10), $currency)
                : Money::normalize($amount);

            $line = new HrPayrollItemLine;
            $line->forceFill([
                'payroll_item_id' => $locked->id,
                'type' => $type,
                'label' => $label,
                'amount' => $computed,
                'is_percentage' => $isPercentage,
                'percentage' => $isPercentage ? bcadd($amount, '0', 4) : null,
            ])->save();

            $this->recalculate($locked);
            $this->recalculateRun($run);

            return $line;
        });
    }

    public function removePayrollItemLine(HrPayrollItemLine $line): void
    {
        DB::connection('tenant')->transaction(function () use ($line): void {
            [$run, $item] = $this->lockEditable($line->item);
            $line->delete();
            $this->recalculate($item);
            $this->recalculateRun($run);
        });
    }

    public function recalculatePayrollItem(HrPayrollItem $item): HrPayrollItem
    {
        return DB::connection('tenant')->transaction(function () use ($item): HrPayrollItem {
            [$run, $locked] = $this->lockEditable($item);
            $this->recalculate($locked);
            $this->recalculateRun($run);

            return $locked;
        });
    }

    /**
     * processing → finalized: the run and its lines are locked for good.
     */
    public function finalizePayrollRun(HrPayrollRun $run, ?User $by = null): HrPayrollRun
    {
        return DB::connection('tenant')->transaction(function () use ($run, $by): HrPayrollRun {
            $locked = $this->lockRun($run);

            if ($locked->status !== HrPayrollRun::PROCESSING) {
                throw ApiException::invalidTransition($locked->status, HrPayrollRun::FINALIZED);
            }

            $this->recalculateRun($locked);
            $locked->forceFill(['status' => HrPayrollRun::FINALIZED, 'finalized_at' => now(), 'finalized_by_user_id' => $by?->id])->save();

            return $locked;
        });
    }

    /**
     * One payslip paid; the last one pays the run.
     */
    public function markPayrollItemPaid(HrPayrollItem $item): HrPayrollItem
    {
        return DB::connection('tenant')->transaction(function () use ($item): HrPayrollItem {
            $run = $this->lockRun($item->run);

            if ($run->status !== HrPayrollRun::FINALIZED) {
                throw ApiException::unprocessable('payroll_run_not_finalized', 'Only items of a finalized run can be paid.');
            }

            /** @var HrPayrollItem $locked */
            $locked = HrPayrollItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($locked->status === HrPayrollItem::PAID) {
                throw ApiException::invalidTransition($locked->status, HrPayrollItem::PAID);
            }

            $locked->forceFill(['status' => HrPayrollItem::PAID, 'paid_at' => now()])->save();

            if (! HrPayrollItem::query()->where('payroll_run_id', $run->id)->where('status', HrPayrollItem::PENDING)->exists()) {
                $this->pay($run);
            }

            return $locked;
        });
    }

    /**
     * finalized → paid: every item is paid, and the run posts once.
     */
    public function markPayrollRunPaid(HrPayrollRun $run): HrPayrollRun
    {
        return DB::connection('tenant')->transaction(function () use ($run): HrPayrollRun {
            $locked = $this->lockRun($run);

            if ($locked->status !== HrPayrollRun::FINALIZED) {
                throw ApiException::invalidTransition($locked->status, HrPayrollRun::PAID);
            }

            HrPayrollItem::query()->where('payroll_run_id', $locked->id)->where('status', HrPayrollItem::PENDING)
                ->update(['status' => HrPayrollItem::PAID, 'paid_at' => now(), 'updated_at' => now()]);
            $this->pay($locked);

            return $locked;
        });
    }

    public function getPayslip(HrEmployee $employee, HrPayrollRun $run): HrPayrollItem
    {
        return HrPayrollItem::query()->with(['lines', 'run', 'employee.user:id,name,email'])
            ->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->first()
            ?? throw new ApiException('payslip_not_found', 'This employee has no payslip in this run.', 404);
    }

    /**
     * Payslips of finalized and paid runs, newest first.
     *
     * @return Collection<int, HrPayrollItem>
     */
    public function getPayrollHistory(HrEmployee $employee): Collection
    {
        return HrPayrollItem::query()->with(['run', 'lines'])->where('employee_id', $employee->id)
            ->whereHas('run', static fn ($q) => $q->whereIn('status', [HrPayrollRun::FINALIZED, HrPayrollRun::PAID]))
            ->get()->sortByDesc(static fn (HrPayrollItem $i): string => $i->run->period_start->toDateString())->values();
    }

    /**
     * Inside the caller's transaction, with the run locked.
     */
    private function pay(HrPayrollRun $run): void
    {
        $run->forceFill(['status' => HrPayrollRun::PAID, 'paid_at' => now()])->save();
        // Exactly once: the posting key is the run (§57.3).
        $this->outbox->record('postPayroll', $run, now(), 'payroll:'.$run->id);
    }

    /**
     * @return array{0: HrPayrollRun, 1: HrPayrollItem} the locked run (processing) and item
     */
    private function lockEditable(HrPayrollItem $item): array
    {
        $run = $this->lockRun($item->run);

        if ($run->status !== HrPayrollRun::PROCESSING) {
            throw ApiException::unprocessable('payroll_run_locked', $run->isLocked() ? 'This payroll run is finalized: its lines can no longer change.' : 'Generate the run\'s items first.');
        }

        /** @var HrPayrollItem $locked */
        $locked = HrPayrollItem::query()->lockForUpdate()->findOrFail($item->id);

        return [$run, $locked];
    }

    private function lockRun(HrPayrollRun $run): HrPayrollRun
    {
        /** @var HrPayrollRun */
        return HrPayrollRun::query()->lockForUpdate()->findOrFail($run->id);
    }

    /**
     * gross = base + allowances, bonuses and reimbursements; net = gross −
     * deductions − tax, never negative.
     */
    private function recalculate(HrPayrollItem $item): void
    {
        $sums = ['earnings' => '0', 'deduction' => '0', 'tax' => '0'];

        foreach (HrPayrollItemLine::query()->where('payroll_item_id', $item->id)->get() as $line) {
            $bucket = in_array($line->type, HrPayrollItemLine::EARNINGS, true) ? 'earnings' : $line->type;
            $sums[$bucket] = Money::add($sums[$bucket], (string) $line->amount);
        }

        $gross = Money::add((string) $item->base_salary, $sums['earnings']);
        $net = Money::sub(Money::sub($gross, $sums['deduction']), $sums['tax']);

        if (Money::cmp($net, '0') < 0) {
            throw ApiException::unprocessable('negative_net_pay', 'Deductions and tax cannot exceed gross pay.');
        }

        $item->forceFill([
            'total_allowances' => Money::normalize($sums['earnings']),
            'gross_pay' => $gross,
            'total_deductions' => Money::normalize($sums['deduction']),
            'tax_amount' => Money::normalize($sums['tax']),
            'net_pay' => $net,
        ])->save();
    }

    /**
     * total_deductions = Σ (deductions + tax), as the ledger's payroll liability.
     */
    private function recalculateRun(HrPayrollRun $run): void
    {
        $row = HrPayrollItem::query()->where('payroll_run_id', $run->id)
            ->selectRaw('COALESCE(SUM(gross_pay), 0) as gross, COALESCE(SUM(total_deductions + tax_amount), 0) as deductions, COALESCE(SUM(net_pay), 0) as net')
            ->toBase()->first();

        $run->forceFill([
            'total_gross' => Money::normalize((string) $row->gross),
            'total_deductions' => Money::normalize((string) $row->deductions),
            'total_net' => Money::normalize((string) $row->net),
        ])->save();
    }
}
