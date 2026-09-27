<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\AccountingPresenter;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\FiscalPeriodService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fiscal periods (spec §57.5, §57.8).
 */
final class FiscalPeriodController extends Controller
{
    public function __construct(
        private readonly FiscalPeriodService $periods,
        private readonly AccountingPresenter $presenter,
    ) {}

    public function index(FiscalYear $year): JsonResponse
    {
        return APIResponse::success($this->periods->listPeriodsForYear($year)->map(fn (FiscalPeriod $p): array => $this->presenter->period($p))->values());
    }

    public function store(Request $request, FiscalYear $year): JsonResponse
    {
        return APIResponse::created($this->presenter->period($this->periods->createFiscalPeriod($year, $request->all())), 'Period created');
    }

    public function close(Request $request, FiscalPeriod $period): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->presenter->period($this->periods->closeFiscalPeriod($period, $user)), 'Period closed');
    }

    public function reopen(FiscalPeriod $period): JsonResponse
    {
        return APIResponse::success($this->presenter->period($this->periods->reopenFiscalPeriod($period)), 'Period reopened');
    }
}
