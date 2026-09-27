<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Payments\Models\OrderPayment;
use App\Shared\Support\Money;

/**
 * The accounting requests of order payment rows (spec §57.3), recorded
 * inside the caller's transaction. Amounts, method and account are
 * snapshotted into the request, so a later edit never changes what an
 * earlier posting records; an edit reverses and re-posts with a version.
 */
final readonly class PaymentPostings
{
    public function __construct(private AccountingOutbox $outbox) {}

    /**
     * A successful payment: postOrderPayment, and postGatewayFee when the
     * provider reported a fee.
     */
    public function payment(OrderPayment $row, ?string $postingKey = null): void
    {
        $this->outbox->record('postOrderPayment', $row, $row->paid_at ?? now(), $postingKey ?? 'order_payment:'.$row->id, [
            'amount' => (string) $row->amount_paid,
            'payment_method' => $row->payment_method,
            'account_id' => $row->account_id,
        ]);

        $fee = $row->meta['fee'] ?? null;

        if ($postingKey === null && is_numeric($fee) && Money::isPositive(Money::normalize((string) $fee))) {
            $this->outbox->record('postGatewayFee', $row, $row->paid_at ?? now(), 'gateway_fee:'.$row->id, ['fee' => Money::normalize((string) $fee)]);
        }
    }

    /**
     * A successful refund or chargeback row.
     */
    public function reversal(OrderPayment $row): void
    {
        $chargeback = $row->kind === OrderPayment::CHARGEBACK;

        $this->outbox->record($chargeback ? 'postChargeback' : 'postRefund', $row, $row->paid_at ?? now(), ($chargeback ? 'chargeback:' : 'refund:').$row->id);
    }

    /**
     * An edited manual payment: reverse the current posting, post afresh
     * as the next version. Nothing happens for a payment that was never
     * posted (accounting was off: no retroactive postings, §57.1 rule 5).
     */
    public function edited(OrderPayment $row): void
    {
        $base = 'order_payment:'.$row->id;
        $current = $this->outbox->currentKey($base);

        if ($current === null) {
            return;
        }

        $version = $this->outbox->nextVersion($base);
        $this->outbox->record('reversePosting', $row, now(), 'order_payment_reversal:'.$row->id.':'.$version, ['posting_key' => $current, 'reason' => 'Payment edited']);
        $this->payment($row, $base.':v'.$version);
    }

    public function deleted(OrderPayment $row): void
    {
        $base = 'order_payment:'.$row->id;
        $current = $this->outbox->currentKey($base);

        if ($current !== null) {
            $this->outbox->record('reversePosting', $row, now(), 'order_payment_reversal:'.$row->id.':deleted', ['posting_key' => $current, 'reason' => 'Payment deleted']);
        }
    }
}
