<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\SalesAgents\Http\SalesAgentPresenter;
use App\Modules\SalesAgents\Models\SalesAgent;
use App\Modules\SalesAgents\Models\SalesAgentCommission;
use App\Modules\SalesAgents\Services\SalesAgentCommissionService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Commissions (spec §52.3): approve, then mark paid once paid outside the
 * platform. Marking paid stays available while the module winds down.
 */
final class SalesAgentCommissionController extends Controller
{
    public function __construct(
        private readonly SalesAgentCommissionService $commissions,
        private readonly SalesAgentPresenter $presenter,
        private readonly CurrencyService $currencies,
    ) {}

    public function index(Request $request, SalesAgent $agent): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(SalesAgentCommission::STATUSES)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->commissions->getCommissionsForAgent($agent, $filters)->through(fn (SalesAgentCommission $c): array => $this->presenter->commission($c)));
    }

    /**
     * outstanding = approved and not yet paid.
     */
    public function balance(SalesAgent $agent): JsonResponse
    {
        $totals = $this->commissions->totals($agent);

        return APIResponse::success([
            'sales_agent_id' => $agent->id,
            'outstanding' => $totals[SalesAgentCommission::APPROVED],
            'pending' => $totals[SalesAgentCommission::PENDING],
            'paid' => $totals[SalesAgentCommission::PAID],
            'currency_code' => $this->currencies->baseCurrency(),
        ]);
    }

    public function approve(SalesAgentCommission $commission): JsonResponse
    {
        return APIResponse::success($this->presenter->commission($this->commissions->approveCommission($commission)->load('order')), 'Commission approved');
    }

    public function markPaid(SalesAgentCommission $commission): JsonResponse
    {
        return APIResponse::success($this->presenter->commission($this->commissions->markPaid($commission)->load('order')), 'Commission marked paid');
    }
}
