<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\PlatformCommission;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantPlatformSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Platform commission on tenant sales (spec §15.8, D-138).
 *
 * Tenants take payments with their own gateway keys, so the platform
 * cannot split a payment at charge time. Instead:
 * 1. assess() snapshots the rate and amount into order_payments.meta in
 *    the tenant transaction that makes a live gateway payment successful;
 * 2. record() copies it, idempotently, into the landlord ledger after that
 *    transaction commits (refunds add a pro-rata negative row), and the
 *    tenant's daily maintenance re-runs record() as a safety net;
 * 3. the monthly run charges each tenant's net pending commission through
 *    the saved subscription authorization as a "commission" charge.
 */
final readonly class PlatformCommissionService
{
    public function __construct(
        private PlatformSettingsService $platform,
        private TenantPlatformSettingsService $tenantPlatform,
    ) {}

    /**
     * The commission snapshot for a payment becoming successful, or null
     * when none applies (disabled, test mode, not an online gateway
     * payment, or a zero rate).
     *
     * @return array{rate: string, amount: string, currency_code: string}|null
     */
    public function assess(OrderPayment $payment): ?array
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || ! $this->platform->isCommissionEnabled()
            || $payment->mode !== 'live' || $payment->kind !== OrderPayment::PAYMENT || $payment->payment_method !== 'gateway') {
            return null;
        }

        $rate = Money::normalize($this->tenantPlatform->getEffectiveCommissionRate($tenant));

        if (! Money::isPositive($rate)) {
            return null;
        }

        return [
            'rate' => $rate,
            'amount' => Money::round(bcmul((string) $payment->amount_paid, bcdiv($rate, '100', 12), 12), (string) $payment->currency_code),
            'currency_code' => (string) $payment->currency_code,
        ];
    }

    /**
     * Writes the ledger row of a successful payment or refund, in the
     * current tenant's context. Safe to repeat.
     */
    public function record(OrderPayment $row): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || $row->status !== OrderPayment::SUCCESSFUL) {
            return;
        }

        $orderNumber = $row->order()->value('order_number');

        if ($row->kind === OrderPayment::PAYMENT) {
            $snapshot = (array) ($row->meta['platform_commission'] ?? []);

            if ($snapshot === []) {
                return;
            }

            $this->insert($tenant, 'payment:'.$row->id, PlatformCommission::PAYMENT, $orderNumber,
                (string) $row->amount_paid, (string) $snapshot['rate'], (string) $snapshot['amount'], (string) $snapshot['currency_code']);

            return;
        }

        $original = $row->refund_of_order_payment_id === null ? null : OrderPayment::query()->find($row->refund_of_order_payment_id);
        $snapshot = (array) ($original?->meta['platform_commission'] ?? []);

        if ($original === null || $snapshot === [] || ! Money::isPositive((string) $original->amount_paid)) {
            return;
        }

        // A refund or lost chargeback: the refunded share of the original
        // commission, never more than it.
        $refunded = Money::sub('0', (string) $row->amount_paid);
        $share = bcdiv($refunded, (string) $original->amount_paid, 12);
        $amount = Money::round(bcmul((string) $snapshot['amount'], Money::min($share, '1'), 12), (string) $snapshot['currency_code']);

        $this->insert($tenant, 'refund:'.$row->id, PlatformCommission::REFUND, $orderNumber,
            Money::sub('0', $refunded), (string) $snapshot['rate'], Money::sub('0', $amount), (string) $snapshot['currency_code']);
    }

    /**
     * The daily safety net: re-records the last week's live gateway
     * payments and refunds (a crash after the tenant commit loses nothing).
     */
    public function reconcile(): int
    {
        $count = 0;

        OrderPayment::query()
            ->where('mode', 'live')
            ->where('status', OrderPayment::SUCCESSFUL)
            ->where('updated_at', '>=', now()->subDays(7))
            ->where(static fn ($q) => $q->whereNotNull('meta->platform_commission')
                ->orWhereIn('kind', [OrderPayment::REFUND, OrderPayment::CHARGEBACK]))
            ->chunkById(200, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    $this->record($row);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Claims a tenant's pending commission in the subscription's currency
     * for one charge, inside the caller's landlord transaction. Null when
     * the net amount is not positive (refunds can exceed new sales).
     *
     * @return array{ids: list<int>, amount: string}|null
     */
    public function claimBillable(Subscription $subscription): ?array
    {
        $rows = PlatformCommission::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('status', PlatformCommission::PENDING)
            ->where('currency_code', $subscription->currency_code)
            ->lockForUpdate()
            ->get(['id', 'amount']);

        $amount = Money::round($rows->reduce(static fn (string $sum, PlatformCommission $c): string => Money::add($sum, (string) $c->amount), '0'), $subscription->currency_code);

        if (! Money::isPositive($amount)) {
            return null;
        }

        return ['ids' => $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all(), 'amount' => $amount];
    }

    /**
     * @param  list<int>  $ids
     */
    public function markBilled(array $ids, PaymentTransaction $charge): void
    {
        PlatformCommission::query()->whereIn('id', $ids)->where('status', PlatformCommission::PENDING)
            ->update(['status' => PlatformCommission::BILLED, 'payment_transaction_id' => $charge->id, 'updated_at' => now()]);
    }

    public function markCollected(PaymentTransaction $charge): void
    {
        PlatformCommission::query()->where('payment_transaction_id', $charge->id)->where('status', PlatformCommission::BILLED)
            ->update(['status' => PlatformCommission::COLLECTED, 'collected_at' => now(), 'updated_at' => now()]);
    }

    /**
     * A failed charge returns its rows to pending for the next run.
     */
    public function release(PaymentTransaction $charge): void
    {
        PlatformCommission::query()->where('payment_transaction_id', $charge->id)->where('status', PlatformCommission::BILLED)
            ->update(['status' => PlatformCommission::PENDING, 'payment_transaction_id' => null, 'updated_at' => now()]);
    }

    public function waive(PlatformCommission $commission, int $platformUserId, string $reason): PlatformCommission
    {
        return DB::connection('landlord')->transaction(function () use ($commission, $platformUserId, $reason): PlatformCommission {
            /** @var PlatformCommission $locked */
            $locked = PlatformCommission::query()->whereKey($commission->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PlatformCommission::PENDING) {
                throw ApiException::invalidTransition($locked->status, PlatformCommission::WAIVED);
            }

            $locked->forceFill(['status' => PlatformCommission::WAIVED, 'waived_by' => $platformUserId, 'waived_reason' => mb_substr($reason, 0, 255)])->save();

            ActivityRecorder::landlord('billing', "Commission {$locked->source_reference} of tenant {$locked->tenant_id} waived", $locked, ['reason' => $locked->waived_reason]);

            return $locked;
        });
    }

    private function insert(Tenant $tenant, string $source, string $kind, ?string $orderNumber, string $base, string $rate, string $amount, string $currency): void
    {
        PlatformCommission::query()->insertOrIgnore([
            'tenant_id' => $tenant->getTenantKey(),
            'source_reference' => $source,
            'kind' => $kind,
            'order_number' => $orderNumber,
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $amount,
            'currency_code' => strtoupper($currency),
            'status' => PlatformCommission::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
