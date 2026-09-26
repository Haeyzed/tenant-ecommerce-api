<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Payments recorded by hand (spec §40.6): cash, bank transfer, cheque and
 * other. Only these manual rows can be edited or deleted; gateway, refund
 * and chargeback rows are system records.
 */
final readonly class OrderPaymentLedgerService
{
    public function __construct(
        private OrderService $orders,
        private OrderPaymentService $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $data  payment_method, amount, amount_received, notes, paid_at
     */
    public function recordPayment(Order $order, array $data, User $by, ?string $idempotencyKey = null): OrderPayment
    {
        $validated = $this->validate($data, true);

        if ($order->status === Order::CANCELLED) {
            throw ApiException::unprocessable('order_cancelled', 'A cancelled order takes no payments.');
        }

        $payment = DB::connection('tenant')->transaction(function () use ($order, $validated, $by, $idempotencyKey): OrderPayment {
            Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null && ($existing = OrderPayment::query()->where('order_id', $order->id)->where('kind', OrderPayment::PAYMENT)->where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $existing;
            }

            $amount = Money::normalize((string) $validated['amount']);
            $received = isset($validated['amount_received']) ? Money::normalize((string) $validated['amount_received']) : null;

            if ($received !== null && Money::cmp($received, $amount) < 0) {
                throw ApiException::unprocessable('amount_received_too_low', 'The amount received is less than the amount paid.');
            }

            $row = new OrderPayment;
            $row->forceFill([
                'order_id' => $order->id,
                'kind' => OrderPayment::PAYMENT,
                'payment_method' => $validated['payment_method'],
                'mode' => OrderPaymentService::modeOf($order),
                'status' => OrderPayment::SUCCESSFUL,
                'reference' => 'MAN-'.Str::upper(Str::random(16)),
                'idempotency_key' => $idempotencyKey,
                'amount_due' => $this->payments->balance($order),
                'amount_received' => $received,
                'amount_paid' => $amount,
                'change_given' => $received === null ? null : Money::sub($received, $amount),
                'currency_code' => $order->currency_code,
                'recorded_by_user_id' => $by->id,
                'paid_at' => $validated['paid_at'] ?? now(),
                'notes' => $validated['notes'] ?? null,
            ])->save();

            $this->orders->recalculatePaymentStatus($order);

            return $row;
        });

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePayment(OrderPayment $payment, array $data): OrderPayment
    {
        $this->assertManual($payment);
        $validated = $this->validate($data, false);

        DB::connection('tenant')->transaction(function () use ($payment, $validated): void {
            /** @var OrderPayment $locked */
            $locked = OrderPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (isset($validated['amount']) && $locked->reversals()->whereIn('status', [OrderPayment::PENDING, OrderPayment::SUCCESSFUL])->exists()) {
                throw ApiException::unprocessable('payment_refunded', 'A refunded payment\'s amount cannot change.');
            }

            $locked->forceFill(array_filter([
                'payment_method' => $validated['payment_method'] ?? null,
                'amount_paid' => isset($validated['amount']) ? Money::normalize((string) $validated['amount']) : null,
                'notes' => $validated['notes'] ?? null,
                'paid_at' => $validated['paid_at'] ?? null,
            ], static fn ($v): bool => $v !== null))->save();

            $payment->setRawAttributes($locked->getAttributes(), true);
            $this->orders->recalculatePaymentStatus($locked->order);
        });

        return $payment;
    }

    public function deletePayment(OrderPayment $payment): void
    {
        $this->assertManual($payment);

        if ($payment->reversals()->exists()) {
            throw ApiException::unprocessable('payment_refunded', 'A refunded payment cannot be deleted.');
        }

        DB::connection('tenant')->transaction(function () use ($payment): void {
            $order = $payment->order;
            $payment->delete();
            $this->orders->recalculatePaymentStatus($order);
        });
    }

    /**
     * Staff resolution of a gateway refund whose outcome was unknown
     * (§40.4), after checking the provider dashboard.
     */
    public function resolveRefund(OrderPayment $refund, string $outcome, ?string $providerReference = null): OrderPayment
    {
        if ($refund->kind !== OrderPayment::REFUND || $refund->status !== OrderPayment::PENDING) {
            throw ApiException::unprocessable('refund_not_pending', 'Only a pending refund can be resolved.');
        }

        if (! in_array($outcome, [OrderPayment::SUCCESSFUL, OrderPayment::FAILED], true)) {
            throw ApiException::unprocessable('validation_failed', 'The outcome is successful or failed.');
        }

        $this->payments->completeRefund($refund, $outcome, $providerReference, $outcome === OrderPayment::FAILED ? 'resolved_failed' : null);

        return $refund->refresh();
    }

    /**
     * @return Collection<int, OrderPayment>
     */
    public function listPaymentsForOrder(Order $order): Collection
    {
        return OrderPayment::query()->with('recorder:id,name')->where('order_id', $order->id)->orderBy('id')->get();
    }

    public function getBalanceForOrder(Order $order): string
    {
        return $this->payments->balance($order);
    }

    private function assertManual(OrderPayment $payment): void
    {
        if (! $payment->isManual()) {
            throw ApiException::unprocessable('payment_not_manual', 'Only payments recorded by hand can be changed.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return validator($data, [
            'payment_method' => [$req, Rule::in(OrderPayment::MANUAL_METHODS)],
            'amount' => [$req, 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999999'],
            'amount_received' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'paid_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ])->validate();
    }
}
