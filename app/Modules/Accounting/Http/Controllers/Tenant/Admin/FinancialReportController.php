<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Financial reports (spec §57.6), generated on demand from the ledger.
 * Ranges default to the current month; "as of" defaults to today.
 */
final class FinancialReportController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function profitAndLoss(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return APIResponse::success($this->accounting->getProfitAndLoss($from, $to));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        return APIResponse::success($this->accounting->getBalanceSheet($this->asOf($request)));
    }

    public function trialBalance(Request $request): JsonResponse
    {
        return APIResponse::success($this->accounting->getTrialBalance($this->asOf($request)));
    }

    public function generalLedger(Request $request, Account $account): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $rows = $this->accounting->getGeneralLedger($account, $from, $to);

        return APIResponse::success([
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $this->accounting->currency(),
            'opening_balance' => $rows->first()['opening_balance'],
            'lines' => $rows->slice(1)->values(),
        ]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return APIResponse::success($this->accounting->getCashFlowStatement($from, $to));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $today = $this->accounting->today();

        return [
            isset($validated['from']) ? Carbon::parse($validated['from']) : $today->copy()->startOfMonth(),
            isset($validated['to']) ? Carbon::parse($validated['to']) : $today,
        ];
    }

    private function asOf(Request $request): Carbon
    {
        $asOf = $request->validate(['as_of' => ['sometimes', 'date_format:Y-m-d']])['as_of'] ?? null;

        return $asOf === null ? $this->accounting->today() : Carbon::parse($asOf);
    }
}
