<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\AccountingPresenter;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\FiscalPeriodService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fiscal years (spec §57.8). Creating a year creates its monthly periods
 * unless monthly_periods is false.
 */
final class FiscalYearController extends Controller
{
    public function __construct(
        private readonly FiscalPeriodService $periods,
        private readonly AccountingPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->periods->listFiscalYears()->map(fn (FiscalYear $y): array => $this->presenter->year($y))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $year = $this->periods->createFiscalYear($request->all());

        return APIResponse::created([...$this->presenter->year($year), 'periods' => $year->periods->map(fn ($p): array => $this->presenter->period($p))->values()->all()], 'Fiscal year created');
    }

    public function close(FiscalYear $year): JsonResponse
    {
        return APIResponse::success($this->presenter->year($this->periods->closeFiscalYear($year)), 'Fiscal year closed');
    }
}
