<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fiscal years and periods (spec §57.3, §57.5). Periods lie within their
 * year and never overlap; a closed period accepts no entries. A new year
 * may be created with monthly periods in one call.
 */
final readonly class FiscalPeriodService
{
    public function __construct(
        private AccountingService $accounting,
        private TenantSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, starts_on, ends_on, monthly_periods (default true)
     */
    public function createFiscalYear(array $data): FiscalYear
    {
        $validated = validator($data, [
            'name' => ['required', 'string', 'max:60'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'monthly_periods' => ['sometimes', 'boolean'],
        ])->validate();

        $starts = Carbon::parse($validated['starts_on']);
        $ends = Carbon::parse($validated['ends_on']);

        if ($starts->diffInDays($ends) > 400) {
            throw ApiException::unprocessable('fiscal_year_too_long', 'A fiscal year spans at most about thirteen months.');
        }

        return DB::connection('tenant')->transaction(function () use ($validated, $starts, $ends): FiscalYear {
            if (FiscalYear::query()->whereDate('starts_on', '<=', $ends)->whereDate('ends_on', '>=', $starts)->lockForUpdate()->exists()) {
                throw ApiException::unprocessable('fiscal_year_overlap', 'This year overlaps an existing fiscal year.');
            }

            $year = FiscalYear::query()->create(['name' => $validated['name'], 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString()]);

            if ($validated['monthly_periods'] ?? true) {
                for ($cursor = $starts->copy(); $cursor->lte($ends); $cursor = $cursor->copy()->addMonthNoOverflow()->startOfMonth()) {
                    $end = $cursor->copy()->endOfMonth()->min($ends);
                    $period = new FiscalPeriod(['name' => $cursor->format('F Y'), 'starts_on' => $cursor->toDateString(), 'ends_on' => $end->toDateString()]);
                    $period->forceFill(['fiscal_year_id' => $year->id])->save();
                }
            }

            return $year->load('periods');
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, starts_on, ends_on
     */
    public function createFiscalPeriod(FiscalYear $year, array $data): FiscalPeriod
    {
        $validated = validator($data, [
            'name' => ['required', 'string', 'max:60'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ])->validate();

        return DB::connection('tenant')->transaction(static function () use ($year, $validated): FiscalPeriod {
            /** @var FiscalYear $locked */
            $locked = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);

            if ($locked->status === FiscalYear::CLOSED) {
                throw ApiException::unprocessable('fiscal_year_closed', 'This fiscal year is closed.');
            }

            if ($validated['starts_on'] < $locked->starts_on->toDateString() || $validated['ends_on'] > $locked->ends_on->toDateString()) {
                throw ApiException::unprocessable('period_outside_year', 'A period must lie within its fiscal year.');
            }

            if (FiscalPeriod::query()->whereDate('starts_on', '<=', $validated['ends_on'])->whereDate('ends_on', '>=', $validated['starts_on'])->exists()) {
                throw ApiException::unprocessable('period_overlap', 'This period overlaps an existing period.');
            }

            $period = new FiscalPeriod(['name' => $validated['name'], 'starts_on' => $validated['starts_on'], 'ends_on' => $validated['ends_on']]);
            $period->forceFill(['fiscal_year_id' => $locked->id])->save();

            return $period;
        });
    }

    /**
     * Locks the period and finalises its cached balances; the closing
     * balances carry forward as the next period's openings (§57.5).
     */
    public function closeFiscalPeriod(FiscalPeriod $period, ?User $by = null): FiscalPeriod
    {
        DB::connection('tenant')->transaction(function () use ($period, $by): void {
            /** @var FiscalPeriod $locked */
            $locked = FiscalPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($locked->status === FiscalPeriod::CLOSED) {
                throw ApiException::invalidTransition(FiscalPeriod::CLOSED, FiscalPeriod::CLOSED);
            }

            $accountIds = DB::connection('tenant')->table('journal_entry_lines as l')->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
                ->where('je.fiscal_period_id', $locked->id)->distinct()->pluck('l.account_id')
                ->merge(DB::connection('tenant')->table('account_balances')->where('fiscal_period_id', $locked->id)->pluck('account_id'))->unique();

            foreach (Account::query()->with('category')->whereIn('id', $accountIds)->get() as $account) {
                $this->accounting->recalculateAccountBalance($account, $locked);
            }

            $locked->forceFill(['status' => FiscalPeriod::CLOSED, 'closed_at' => now(), 'closed_by_user_id' => $by?->id])->save();
            $period->setRawAttributes($locked->getAttributes(), true);
        });

        return $period;
    }

    /**
     * The admin override (§57.5, A-45): only while the year is open.
     */
    public function reopenFiscalPeriod(FiscalPeriod $period): FiscalPeriod
    {
        DB::connection('tenant')->transaction(static function () use ($period): void {
            /** @var FiscalPeriod $locked */
            $locked = FiscalPeriod::query()->with('year')->lockForUpdate()->findOrFail($period->id);

            if ($locked->status !== FiscalPeriod::CLOSED) {
                throw ApiException::invalidTransition($locked->status, FiscalPeriod::OPEN);
            }

            if ($locked->year->status === FiscalYear::CLOSED) {
                throw ApiException::unprocessable('fiscal_year_closed', 'A period of a closed fiscal year cannot be reopened.');
            }

            $locked->forceFill(['status' => FiscalPeriod::OPEN, 'closed_at' => null, 'closed_by_user_id' => null])->save();
            $period->setRawAttributes($locked->getAttributes(), true);
        });

        return $period;
    }

    /**
     * Requires every period closed; no closing entries (A-46).
     */
    public function closeFiscalYear(FiscalYear $year): FiscalYear
    {
        DB::connection('tenant')->transaction(static function () use ($year): void {
            /** @var FiscalYear $locked */
            $locked = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);

            if ($locked->status === FiscalYear::CLOSED) {
                throw ApiException::invalidTransition(FiscalYear::CLOSED, FiscalYear::CLOSED);
            }

            if ($locked->periods()->where('status', FiscalPeriod::OPEN)->exists()) {
                throw ApiException::unprocessable('periods_open', 'Close every period of the year first.');
            }

            $locked->forceFill(['status' => FiscalYear::CLOSED, 'closed_at' => now()])->save();
            $year->setRawAttributes($locked->getAttributes(), true);
        });

        return $year;
    }

    public function getCurrentPeriod(): ?FiscalPeriod
    {
        $today = Carbon::now((string) ($this->settings->get('timezone') ?: 'UTC'))->toDateString();

        return FiscalPeriod::query()->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->first();
    }

    /**
     * @return Collection<int, FiscalYear>
     */
    public function listFiscalYears(): Collection
    {
        return FiscalYear::query()->withCount('periods')->orderByDesc('starts_on')->get();
    }

    /**
     * @return Collection<int, FiscalPeriod>
     */
    public function listPeriodsForYear(FiscalYear $year): Collection
    {
        return $year->periods()->orderBy('starts_on')->get();
    }
}
