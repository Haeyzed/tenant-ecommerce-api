<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Jobs\CheckRefundOutcome;
use App\Modules\Payments\Jobs\VerifyOrderPayment;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\DTOs\ChargeRequest;
use App\Shared\Payments\DTOs\WebhookEvent;
use App\Shared\Payments\PaymentGatewayException;
use App\Shared\Payments\PaymentGatewayFactory;
use App\Shared\Support\FrontendUrl;
use App\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Storefront payments against orders (spec §40). A row moves pending →
 * successful | failed exactly once, under its row lock; every side effect
 * runs in that transition's transaction. Provider calls always happen
 * outside database transactions.
 */
final readonly class OrderPaymentService
{
    public function __construct(
        private PaymentGatewayFactory $factory,
        private OrderService $orders,
        private NotificationDispatchService $notifications,
    ) {}

    public static function modeOf(Order $order): string
    {
        return $order->is_test ? 'test' : 'live';
    }

    public function netPaid(Order $order): string
    {
        return Money::normalize((string) OrderPayment::query()->where('order_id', $order->id)->where('status', OrderPayment::SUCCESSFUL)->sum('amount_paid'));
    }

    /**
     * total − net paid, never below zero.
     */
    public function balance(Order $order): string
    {
        return Money::max(Money::normalize(0), Money::sub(Money::normalize((string) $order->total), $this->netPaid($order)));
    }

    /**
     * Active online providers of a mode that can charge the currency.
     *
     * @return list<string>
     */
    public function eligibleProviders(string $currency, string $mode): array
    {
        $supported = $this->factory->supportedProvidersForCurrency($currency, 'tenant');

        return TenantPaymentSetting::query()->where('mode', $mode)->where('is_active', true)->where('enabled_for_online', true)
            ->orderBy('provider')->pluck('provider')
            ->filter(static fn (string $p): bool => in_array($p, $supported, true))
            ->values()->all();
    }

    /**
     * POST /api/orders/{order}/pay (§40.2).
     *
     * @return array{checkout_url: string, reference: string}
     */
    public function initiateOrderPayment(Order $order, string $provider, ?string $idempotencyKey = null): array
    {
        if ($order->status === Order::CANCELLED || ! in_array($order->payment_status, ['unpaid', 'partially_paid', 'failed'], true)) {
            throw ApiException::unprocessable('order_not_payable', 'This order cannot be paid.');
        }

        if ($order->payment_expires_at !== null && $order->payment_expires_at->isPast()) {
            throw ApiException::conflict('order_expired', 'The time to pay for this order has passed.');
        }

        $mode = self::modeOf($order);

        if (! in_array($provider, $this->eligibleProviders($order->currency_code, $mode), true)) {
            throw ApiException::unprocessable('gateway_unavailable', 'This payment method is not available for this order.');
        }

        // One attempt at a time: settle an earlier attempt first.
        $this->verifyPendingForOrder($order);
        $pending = OrderPayment::query()->where('order_id', $order->id)->where('kind', OrderPayment::PAYMENT)
            ->where('payment_method', 'gateway')->where('status', OrderPayment::PENDING)->first();

        if ($pending !== null) {
            throw ApiException::conflict('payment_in_progress', 'A payment for this order is already in progress.', [
                'reference' => $pending->reference,
                'checkout_url' => $pending->meta['checkout_url'] ?? null,
            ]);
        }

        $order->refresh();
        $amount = $this->balance($order);

        if (! Money::isPositive($amount)) {
            throw ApiException::unprocessable('order_not_payable', 'Nothing is due on this order.');
        }

        $payment = new OrderPayment;
        $payment->forceFill([
            'order_id' => $order->id,
            'kind' => OrderPayment::PAYMENT,
            'payment_method' => 'gateway',
            'provider' => $provider,
            'mode' => $mode,
            'status' => OrderPayment::PENDING,
            'reference' => 'ORD-'.$order->order_number.'-'.Str::upper(Str::random(10)),
            'idempotency_key' => $idempotencyKey,
            'amount_due' => $amount,
            'amount_paid' => $amount,
            'currency_code' => $order->currency_code,
        ])->save();

        try {
            $result = $this->factory->forTenant($provider, $mode)->initiateCharge(new ChargeRequest(
                amount: $amount,
                currencyCode: $order->currency_code,
                reference: $payment->reference,
                customerEmail: (string) $order->customer_email,
                customerName: $order->customer_name,
                callbackUrl: $this->callbackUrl($order),
                metadata: ['order_number' => $order->order_number],
                description: 'Order '.$order->order_number,
            ));
        } catch (PaymentGatewayException $e) {
            if (! $e->pending) {
                $this->completeGatewayPayment($payment, ['status' => 'failed', 'failure_reason' => 'initiation_rejected']);

                throw new ApiException('gateway_error', 'The payment provider refused the payment. Try again or choose another method.', 502);
            }

            throw new ApiException('gateway_unavailable', 'The payment provider did not respond. Try again shortly.', 503);
        }

        $payment->forceFill([
            'provider_reference' => $result['provider_reference'],
            'meta' => [...(array) $payment->meta, 'checkout_url' => $result['checkout_url']],
        ])->save();

        VerifyOrderPayment::dispatch((string) tenant()?->getTenantKey(), $payment->id, 1)->delay(now()->addMinutes(15));

        return ['checkout_url' => $result['checkout_url'], 'reference' => $payment->reference];
    }

    /**
     * Client verification (POST /api/payments/verify) and delayed checks:
     * asks the provider and applies a definitive result.
     */
    public function verifyOrderPayment(OrderPayment $payment): OrderPayment
    {
        if ($payment->status !== OrderPayment::PENDING || $payment->payment_method !== 'gateway' || $payment->kind !== OrderPayment::PAYMENT) {
            return $payment;
        }

        try {
            $result = $this->factory->forTenant((string) $payment->provider, $payment->mode)->verifyTransaction($payment->reference);
        } catch (PaymentGatewayException) {
            return $payment;
        }

        if ($result['status'] !== 'pending') {
            $this->completeGatewayPayment($payment, $result);
        }

        return $payment->refresh();
    }

    public function verifyPendingForOrder(Order $order): void
    {
        OrderPayment::query()->where('order_id', $order->id)->where('kind', OrderPayment::PAYMENT)
            ->where('payment_method', 'gateway')->where('status', OrderPayment::PENDING)
            ->get()->each(fn (OrderPayment $p) => $this->verifyOrderPayment($p));
    }

    /**
     * The single transition point of a gateway payment (§40.2). The
     * provider's amount and currency must match the row. A success reported
     * for a row that already failed is recorded as a new successful row:
     * money the provider captured is never ignored.
     *
     * @param  array{status: string, amount?: string|null, currency_code?: string|null, fee?: string|null, provider_reference?: string|null, failure_reason?: string|null}  $verified
     */
    public function completeGatewayPayment(OrderPayment $payment, array $verified): void
    {
        $outcome = null;

        DB::connection('tenant')->transaction(function () use ($payment, $verified, &$outcome): void {
            /** @var OrderPayment $locked */
            $locked = OrderPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderPayment::PENDING) {
                if ($locked->status === OrderPayment::FAILED && $verified['status'] === 'successful' && ! $this->mismatched($locked, $verified)) {
                    $outcome = $this->recordLateSuccess($locked, $verified);
                }

                return;
            }

            if ($verified['status'] === 'successful' && $this->mismatched($locked, $verified)) {
                $locked->forceFill(['status' => OrderPayment::FAILED, 'meta' => [...(array) $locked->meta, 'failure_reason' => 'amount_mismatch',
                    'provider_amount' => $verified['amount'] ?? null, 'provider_currency' => $verified['currency_code'] ?? null]])->save();
                $outcome = 'mismatch';

                return;
            }

            if ($verified['status'] === 'successful') {
                $locked->forceFill([
                    'status' => OrderPayment::SUCCESSFUL,
                    'paid_at' => now(),
                    'provider_reference' => $verified['provider_reference'] ?? $locked->provider_reference,
                    'meta' => [...(array) $locked->meta, 'fee' => $verified['fee'] ?? null],
                ])->save();
                $outcome = 'successful';
            } else {
                $locked->forceFill(['status' => OrderPayment::FAILED, 'meta' => [...(array) $locked->meta, 'failure_reason' => $verified['failure_reason'] ?? 'declined']])->save();
                $outcome = 'failed';
            }

            $payment->setRawAttributes($locked->getAttributes(), true);

            if ($outcome === 'successful') {
                $this->orders->recalculatePaymentStatus($locked->order);
            }
        });

        $order = $payment->order()->first();

        if ($order === null) {
            return;
        }

        match ($outcome) {
            'failed' => $this->orders->markFailed($order, (string) ($verified['failure_reason'] ?? 'declined')),
            'mismatch' => $this->notifications->dispatch('order.payment_needs_review', $order, [
                'order_number' => $order->order_number,
                'provider_amount' => (string) ($verified['amount'] ?? '?').' '.(string) ($verified['currency_code'] ?? ''),
                'order_total' => Money::format((string) $payment->amount_paid, $payment->currency_code),
            ]),
            default => null,
        };
    }

    /**
     * Routes a verified webhook (§40.3). Unknown references are logged and
     * acknowledged.
     */
    public function handleWebhookEvent(WebhookEvent $event, string $provider, string $mode): void
    {
        match ($event->type) {
            WebhookEvent::CHARGE_SUCCEEDED, WebhookEvent::CHARGE_FAILED => $this->chargeEvent($event, $provider, $mode),
            WebhookEvent::REFUND_PROCESSED, WebhookEvent::REFUND_FAILED => $this->refundEvent($event, $provider, $mode),
            WebhookEvent::DISPUTE_OPENED, WebhookEvent::DISPUTE_WON, WebhookEvent::DISPUTE_LOST => $this->handleDispute($event, $provider, $mode),
            default => null,
        };
    }

    /**
     * The only refund path (§40.4), two-phase: reserve the capacity under a
     * lock on the original row, then call the provider outside the
     * transaction. Manual methods complete at once (ledger only).
     */
    public function refund(OrderPayment $payment, string $amount, string $reason, ?User $by = null, ?string $idempotencyKey = null, ?OrderReturn $return = null): OrderPayment
    {
        $amount = Money::normalize($amount);

        if (! Money::isPositive($amount)) {
            throw ApiException::unprocessable('refund_invalid', 'The refund amount must be positive.');
        }

        if (in_array($payment->payment_method, ['exchange_credit', 'card_terminal', 'gift_card'], true)) {
            throw ApiException::unprocessable('refund_method_unsupported', 'This payment cannot be refunded here.');
        }

        /** @var OrderPayment $refund */
        $refund = DB::connection('tenant')->transaction(function () use ($payment, $amount, $reason, $by, $idempotencyKey, $return): OrderPayment {
            /** @var OrderPayment $original */
            $original = OrderPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null && ($existing = OrderPayment::query()->where('order_id', $original->order_id)->where('kind', OrderPayment::REFUND)->where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $existing;
            }

            if ($original->kind !== OrderPayment::PAYMENT || $original->status !== OrderPayment::SUCCESSFUL) {
                throw ApiException::unprocessable('refund_invalid', 'Only a successful payment can be refunded.');
            }

            if (Money::cmp($amount, $this->capacity($original)) > 0) {
                throw ApiException::unprocessable('refund_exceeds_payment', 'The refund exceeds what is left of this payment.', ['refundable' => $this->capacity($original)]);
            }

            $gateway = $original->payment_method === 'gateway';
            $row = new OrderPayment;
            $row->forceFill([
                'order_id' => $original->order_id,
                'kind' => OrderPayment::REFUND,
                'payment_method' => $original->payment_method,
                'provider' => $original->provider,
                'mode' => $original->mode,
                'status' => $gateway ? OrderPayment::PENDING : OrderPayment::SUCCESSFUL,
                'reference' => 'RFD-'.Str::upper(Str::random(16)),
                'idempotency_key' => $idempotencyKey,
                'amount_paid' => Money::sub('0', $amount),
                'currency_code' => $original->currency_code,
                'refund_of_order_payment_id' => $original->id,
                'order_return_id' => $return?->id,
                'recorded_by_user_id' => $by?->id,
                'paid_at' => $gateway ? null : now(),
                'notes' => mb_substr($reason, 0, 1000),
            ])->save();

            if (! $gateway) {
                $this->orders->recalculatePaymentStatus($original->order);
            }

            return $row;
        });

        if ($refund->status === OrderPayment::SUCCESSFUL) {
            if ($refund->wasRecentlyCreated) {
                $this->notifyRefunded($refund);
            }

            return $refund;
        }

        if (! $refund->wasRecentlyCreated || $refund->provider_reference !== null) {
            return $refund;
        }

        try {
            $result = $this->factory->forTenant((string) $payment->provider, $payment->mode)
                ->refund((string) $payment->provider_reference, $amount, $payment->currency_code, $refund->reference);
        } catch (PaymentGatewayException $e) {
            if (! $e->pending) {
                $this->completeRefund($refund, OrderPayment::FAILED, null, 'provider_rejected');
            } else {
                CheckRefundOutcome::dispatch((string) tenant()?->getTenantKey(), $refund->id)->delay(now()->addHour());
            }

            return $refund->refresh();
        }

        if ($result['refund_reference'] !== null) {
            $refund->forceFill(['provider_reference' => $result['refund_reference']])->save();
        }

        match ($result['status']) {
            'successful' => $this->completeRefund($refund, OrderPayment::SUCCESSFUL, $result['refund_reference']),
            'failed' => $this->completeRefund($refund, OrderPayment::FAILED, $result['refund_reference'], 'provider_failed'),
            default => CheckRefundOutcome::dispatch((string) tenant()?->getTenantKey(), $refund->id)->delay(now()->addHour()),
        };

        return $refund->refresh();
    }

    /**
     * A direct refund with no return (§39.5 refundOrder): successful
     * payments newest first until the amount (default: all net paid) is
     * covered.
     *
     * @return list<OrderPayment>
     */
    public function refundOrder(Order $order, ?string $amount, string $reason, ?User $by = null, ?string $idempotencyKey = null, ?OrderReturn $return = null): array
    {
        $remaining = Money::normalize($amount ?? $this->netPaid($order));

        if (! Money::isPositive($remaining) || Money::cmp($remaining, $this->netPaid($order)) > 0) {
            throw ApiException::unprocessable('refund_exceeds_payment', 'The refund exceeds what was paid.', ['refundable' => $this->netPaid($order)]);
        }

        $refunds = [];
        $payments = OrderPayment::query()->where('order_id', $order->id)->where('kind', OrderPayment::PAYMENT)
            ->where('status', OrderPayment::SUCCESSFUL)->orderByDesc('paid_at')->orderByDesc('id')->get();

        foreach ($payments as $payment) {
            if (! Money::isPositive($remaining)) {
                break;
            }

            $capacity = $this->capacity($payment);

            if (! Money::isPositive($capacity) || in_array($payment->payment_method, ['exchange_credit', 'card_terminal', 'gift_card'], true)) {
                continue;
            }

            $part = Money::min($capacity, $remaining);
            $refunds[] = $this->refund($payment, $part, $reason, $by, $idempotencyKey === null ? null : $idempotencyKey.':'.$payment->id, $return);
            $remaining = Money::sub($remaining, $part);
        }

        if (Money::isPositive($remaining) && $refunds === []) {
            throw ApiException::unprocessable('refund_method_unsupported', 'None of this order\'s payments can be refunded here.');
        }

        return $refunds;
    }

    /**
     * The transition of a refund or chargeback row.
     */
    public function completeRefund(OrderPayment $refund, string $status, ?string $providerReference = null, ?string $failureReason = null): void
    {
        $done = DB::connection('tenant')->transaction(function () use ($refund, $status, $providerReference, $failureReason): bool {
            /** @var OrderPayment $locked */
            $locked = OrderPayment::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderPayment::PENDING) {
                return false;
            }

            $locked->forceFill([
                'status' => $status,
                'paid_at' => $status === OrderPayment::SUCCESSFUL ? now() : null,
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                'meta' => $failureReason === null ? $locked->meta : [...(array) $locked->meta, 'failure_reason' => $failureReason],
            ])->save();

            $refund->setRawAttributes($locked->getAttributes(), true);

            if ($status === OrderPayment::SUCCESSFUL) {
                $this->orders->recalculatePaymentStatus($locked->order);
            }

            return $status === OrderPayment::SUCCESSFUL;
        });

        if ($done && $refund->kind === OrderPayment::REFUND) {
            $this->notifyRefunded($refund);
        }
    }

    /**
     * Chargebacks (§40.5): opened reserves capacity, lost completes like a
     * refund (no restock), won frees the capacity.
     */
    public function handleDispute(WebhookEvent $event, string $provider, string $mode): void
    {
        $original = OrderPayment::query()->where('kind', OrderPayment::PAYMENT)->where('provider', $provider)->where('mode', $mode)
            ->where(static fn ($q) => $q->where('provider_reference', $event->chargeProviderReference ?? '')->orWhere('reference', $event->reference ?? ''))
            ->first();

        if ($original === null || $event->providerReference === null) {
            Log::info('Dispute for an unknown order payment acknowledged.', ['provider' => $provider, 'mode' => $mode, 'type' => $event->type]);

            return;
        }

        $row = DB::connection('tenant')->transaction(function () use ($event, $original, $provider, $mode): OrderPayment {
            OrderPayment::query()->whereKey($original->id)->lockForUpdate()->first();

            $existing = OrderPayment::query()->where('mode', $mode)->where('kind', OrderPayment::CHARGEBACK)->where('provider', $provider)
                ->where('provider_reference', $event->providerReference)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $amount = Money::min(Money::normalize($event->amount ?? (string) $original->amount_paid), Money::max('0', $this->capacity($original)));

            $row = new OrderPayment;
            $row->forceFill([
                'order_id' => $original->order_id,
                'kind' => OrderPayment::CHARGEBACK,
                'payment_method' => $original->payment_method,
                'provider' => $provider,
                'mode' => $mode,
                'status' => OrderPayment::PENDING,
                'reference' => 'CBK-'.Str::upper(Str::random(16)),
                'provider_reference' => $event->providerReference,
                'amount_paid' => Money::sub('0', $amount),
                'currency_code' => $original->currency_code,
                'refund_of_order_payment_id' => $original->id,
                'meta' => ['fee' => $event->fee],
            ])->save();

            return $row;
        });

        if ($event->type === WebhookEvent::DISPUTE_OPENED && $row->wasRecentlyCreated) {
            $order = $row->order;
            $this->notifications->dispatch('order.payment_disputed', $order, [
                'order_number' => $order->order_number,
                'amount' => Money::format(Money::sub('0', (string) $row->amount_paid), $row->currency_code),
                'respond_by' => (string) ($event->raw['respond_by'] ?? $event->raw['due_by'] ?? 'the provider deadline'),
            ]);
        }

        if ($event->type === WebhookEvent::DISPUTE_LOST) {
            $this->completeRefund($row, OrderPayment::SUCCESSFUL);
        } elseif ($event->type === WebhookEvent::DISPUTE_WON) {
            $this->completeRefund($row, OrderPayment::FAILED, null, 'dispute_won');
        }
    }

    /**
     * Refunds still pending with no provider reference an hour after
     * creation: the outcome is unknown and never retried automatically
     * (§40.4). Staff are told once.
     */
    public function flagUnresolvedRefunds(): int
    {
        $rows = OrderPayment::query()->where('kind', OrderPayment::REFUND)->where('status', OrderPayment::PENDING)->whereNull('provider_reference')
            ->where('created_at', '<', now()->subHour())->get()
            ->filter(static fn (OrderPayment $r): bool => ! (bool) ($r->meta['review_requested'] ?? false));

        foreach ($rows as $refund) {
            $refund->forceFill(['meta' => [...(array) $refund->meta, 'review_requested' => true]])->save();
            $this->notifications->dispatch('order.refund_needs_review', $refund->order, [
                'order_number' => $refund->order->order_number,
                'amount' => Money::format(Money::sub('0', (string) $refund->amount_paid), $refund->currency_code),
            ]);
        }

        return $rows->count();
    }

    /**
     * amount_paid minus every pending or successful refund and chargeback.
     */
    public function capacity(OrderPayment $payment): string
    {
        $reversed = (string) OrderPayment::query()->where('refund_of_order_payment_id', $payment->id)
            ->whereIn('status', [OrderPayment::PENDING, OrderPayment::SUCCESSFUL])->sum('amount_paid');

        return Money::add(Money::normalize((string) $payment->amount_paid), Money::normalize($reversed));
    }

    private function chargeEvent(WebhookEvent $event, string $provider, string $mode): void
    {
        $payment = $event->reference === null ? null : OrderPayment::query()->where('reference', $event->reference)
            ->where('kind', OrderPayment::PAYMENT)->where('provider', $provider)->where('mode', $mode)->first();

        if ($payment === null) {
            Log::info('Payment webhook for an unknown reference acknowledged.', ['provider' => $provider, 'mode' => $mode]);

            return;
        }

        $this->completeGatewayPayment($payment, [
            'status' => $event->type === WebhookEvent::CHARGE_SUCCEEDED ? 'successful' : 'failed',
            'amount' => $event->amount,
            'currency_code' => $event->currencyCode,
            'fee' => $event->fee,
            'provider_reference' => $event->providerReference,
            'failure_reason' => $event->failureReason,
        ]);
    }

    private function refundEvent(WebhookEvent $event, string $provider, string $mode): void
    {
        $refund = OrderPayment::query()->where('kind', OrderPayment::REFUND)->where('provider', $provider)->where('mode', $mode)
            ->where(static fn ($q) => $q->where('reference', $event->refundReference ?? '')->orWhere('provider_reference', $event->providerReference ?? ''))
            ->first();

        if ($refund === null) {
            Log::info('Refund webhook for an unknown refund acknowledged.', ['provider' => $provider, 'mode' => $mode]);

            return;
        }

        $this->completeRefund($refund, $event->type === WebhookEvent::REFUND_PROCESSED ? OrderPayment::SUCCESSFUL : OrderPayment::FAILED,
            $event->providerReference, $event->type === WebhookEvent::REFUND_FAILED ? ($event->failureReason ?? 'provider_failed') : null);
    }

    /**
     * @param  array<string, mixed>  $verified
     */
    private function mismatched(OrderPayment $payment, array $verified): bool
    {
        return (($verified['amount'] ?? null) !== null && Money::cmp(Money::normalize((string) $verified['amount']), Money::normalize((string) $payment->amount_paid)) !== 0)
            || (($verified['currency_code'] ?? null) !== null && strtoupper((string) $verified['currency_code']) !== $payment->currency_code);
    }

    /**
     * @param  array<string, mixed>  $verified
     */
    private function recordLateSuccess(OrderPayment $failed, array $verified): string
    {
        $providerReference = $verified['provider_reference'] ?? $failed->provider_reference;

        if (OrderPayment::query()->where('order_id', $failed->order_id)->where('status', OrderPayment::SUCCESSFUL)
            ->where('meta->late_success_of', $failed->id)->exists()) {
            return 'none';
        }

        // The failed row gives up its provider reference to the real payment.
        $failed->forceFill(['provider_reference' => null, 'meta' => [...(array) $failed->meta, 'superseded_provider_reference' => $providerReference]])->save();

        $row = new OrderPayment;
        $row->forceFill([
            'order_id' => $failed->order_id,
            'kind' => OrderPayment::PAYMENT,
            'payment_method' => 'gateway',
            'provider' => $failed->provider,
            'mode' => $failed->mode,
            'status' => OrderPayment::SUCCESSFUL,
            'reference' => $failed->reference.'-L',
            'provider_reference' => $providerReference,
            'amount_paid' => $failed->amount_paid,
            'currency_code' => $failed->currency_code,
            'paid_at' => now(),
            'meta' => ['late_success_of' => $failed->id, 'fee' => $verified['fee'] ?? null],
        ])->save();

        $this->orders->recalculatePaymentStatus($failed->order);

        return 'successful';
    }

    private function notifyRefunded(OrderPayment $refund): void
    {
        // A return's refund settles the return, which notifies (§40.4 step 4).
        if ($refund->order_return_id !== null) {
            app(ReturnService::class)->refundSettled((int) $refund->order_return_id);

            return;
        }

        $order = $refund->order;
        $this->notifications->dispatch('order.refunded', $order, [
            'customer_name' => (string) ($order->customer_name ?? ''),
            'order_number' => $order->order_number,
            'amount' => Money::format(Money::sub('0', (string) $refund->amount_paid), $refund->currency_code),
        ]);
    }

    private function callbackUrl(Order $order): ?string
    {
        $tenant = tenant();

        return $tenant instanceof Tenant ? FrontendUrl::storefront($tenant, '/orders/'.$order->id.'/payment-return') : null;
    }
}
