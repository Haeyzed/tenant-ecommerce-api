<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents\Services;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\SalesAgents\Models\SalesAgent;
use App\Modules\SalesAgents\Models\SalesAgentCommission;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Commissions (spec §52.2): one per attributed order, created pending on
 * confirmation, then approved and marked paid by staff (paid outside the
 * platform). Refunds do not adjust them in v1: staff do not approve
 * affected commissions.
 */
final readonly class SalesAgentCommissionService
{
    public function __construct(
        private SalesAgentService $agents,
        private TenantSettingsService $settings,
        private CurrencyService $currencies,
    ) {}

    /**
     * The confirmation hook. Base (A-36) = net merchandise (Σ line_total −
     * tax_amount, after every promotion) less the points discount, in the
     * base currency at the order's rate. Test orders earn nothing.
     */
    public function calculateCommission(Order $order): ?SalesAgentCommission
    {
        if ($order->sales_agent_id === null || $order->is_test || $order->order_type !== 'standard' || ! $this->agents->enabled()) {
            return null;
        }

        $existing = SalesAgentCommission::query()->where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $agent = SalesAgent::query()->find($order->sales_agent_id);

        if ($agent === null) {
            return null;
        }

        $base = $this->currencies->baseCurrency();
        $net = OrderItem::query()->where('order_id', $order->id)->get(['line_total', 'tax_amount'])
            ->reduce(static fn (string $sum, OrderItem $i): string => Money::add($sum, Money::sub((string) $i->line_total, (string) $i->tax_amount)), Money::normalize(0));
        $gross = Money::round(bcmul(Money::sub($net, (string) $order->reward_points_discount_amount), (string) ($order->exchange_rate_used ?? '1'), 12), $base);

        if (! Money::isPositive($gross)) {
            return null;
        }

        $rate = Money::normalize((string) ($agent->commission_rate ?? $this->settings->get('default_sales_agent_commission_rate', '0')));

        $commission = new SalesAgentCommission;
        $commission->forceFill([
            'sales_agent_id' => $agent->id,
            'order_id' => $order->id,
            'gross_amount' => $gross,
            'commission_rate_applied' => $rate,
            'commission_amount' => Money::round(bcdiv(bcmul($gross, $rate, 12), '100', 12), $base),
            'status' => SalesAgentCommission::PENDING,
            'earned_at' => now(),
        ])->save();

        return $commission;
    }

    public function approveCommission(SalesAgentCommission $commission): SalesAgentCommission
    {
        return $this->transition($commission, SalesAgentCommission::PENDING, SalesAgentCommission::APPROVED, 'approved_at');
    }

    public function markPaid(SalesAgentCommission $commission): SalesAgentCommission
    {
        return $this->transition($commission, SalesAgentCommission::APPROVED, SalesAgentCommission::PAID, 'paid_at');
    }

    /**
     * @param  array{status?: string, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, SalesAgentCommission>
     */
    public function getCommissionsForAgent(SalesAgent $agent, array $filters = []): LengthAwarePaginator
    {
        return SalesAgentCommission::query()->with('order:id,order_number,order_source,total,currency_code,placed_at')
            ->where('sales_agent_id', $agent->id)
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('earned_at', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('earned_at', '<=', $filters['to']))
            ->orderByDesc('earned_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Approved and not yet paid (§52.3).
     */
    public function getOutstandingBalance(SalesAgent $agent): string
    {
        return $this->totals($agent)[SalesAgentCommission::APPROVED];
    }

    /**
     * @return array<string, string> status => Σ commission_amount
     */
    public function totals(SalesAgent $agent): array
    {
        $sums = SalesAgentCommission::query()->where('sales_agent_id', $agent->id)->groupBy('status')
            ->selectRaw('status, SUM(commission_amount) AS total')->pluck('total', 'status');

        return collect(SalesAgentCommission::STATUSES)->mapWithKeys(static fn (string $s): array => [$s => Money::normalize((string) ($sums[$s] ?? '0'))])->all();
    }

    private function transition(SalesAgentCommission $commission, string $from, string $to, string $stamp): SalesAgentCommission
    {
        return DB::connection('tenant')->transaction(function () use ($commission, $from, $to, $stamp): SalesAgentCommission {
            /** @var SalesAgentCommission $locked */
            $locked = SalesAgentCommission::query()->lockForUpdate()->findOrFail($commission->id);

            if ($locked->status !== $from) {
                throw ApiException::invalidTransition($locked->status, $to);
            }

            $locked->forceFill(['status' => $to, $stamp => now()])->save();

            return $locked;
        });
    }
}
