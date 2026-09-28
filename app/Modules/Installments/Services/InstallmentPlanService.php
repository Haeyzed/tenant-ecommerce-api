<?php

declare(strict_types=1);

namespace App\Modules\Installments\Services;

use App\Modules\Installments\Models\InstallmentPayment;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\DTOs\ChargeRequest;
use App\Shared\Payments\PaymentGatewayException;
use App\Shared\Payments\PaymentGatewayFactory;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The store's own installment plans (spec §47): layaway-style credit the
 * tenant extends. The first installment is paid through the gateway flow
 * and stores a reusable authorization; later ones are charged with it on
 * their due date. Overdue installments flag the plan as defaulted for
 * staff; nothing is cancelled automatically.
 */
final readonly class InstallmentPlanService
{
    public const int MAX_INSTALLMENTS = 24;

    public function __construct(
        private FeatureAccessService $features,
        private TenantSettingsService $settings,
        private OrderService $orders,
        private PaymentGatewayFactory $factory,
    ) {}

    /**
     * Both gates of §47: the feature and installments_enabled.
     */
    public function offered(): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && $this->features->state($tenant, 'installments') === ModuleState::Enabled
            && (bool) $this->settings->get('installments_enabled', false);
    }

    /**
     * The setting, the minimum amount (base currency), no payment yet, and
     * an open standard order without a plan (§47.4).
     */
    public function isEligible(Order $order): bool
    {
        return $this->ineligibility($order) === null;
    }

    public function ineligibility(Order $order): ?string
    {
        $minimum = $this->settings->get('installments_minimum_order_amount');
        $baseTotal = Money::mul((string) $order->total, (string) ($order->exchange_rate_used ?? '1'));

        return match (true) {
            ! $this->offered() => 'installments_unavailable',
            $order->order_type !== 'standard' || $order->status === Order::CANCELLED || $order->confirmed_at !== null => 'order_not_eligible',
            $minimum !== null && Money::cmp($baseTotal, Money::normalize((string) $minimum)) < 0 => 'order_total_too_low',
            OrderPayment::query()->where('order_id', $order->id)->exists() => 'order_has_payments',
            InstallmentPlan::query()->where('order_id', $order->id)->exists() => 'plan_exists',
            default => null,
        };
    }

    /**
     * Even split; the rounding remainder goes on the last installment. The
     * first is due today. The order no longer expires unpaid.
     */
    public function createPlan(Order $order, int $installments, string $frequency): InstallmentPlan
    {
        Validator::make(['number_of_installments' => $installments, 'frequency' => $frequency], [
            'number_of_installments' => ['required', 'integer', 'min:2', 'max:'.self::MAX_INSTALLMENTS],
            'frequency' => ['required', Rule::in(InstallmentPlan::FREQUENCIES)],
        ])->validate();

        if (! $this->offered()) {
            throw ApiException::forbidden('installments_unavailable', 'Installments are not offered by this store.');
        }

        return DB::connection('tenant')->transaction(function () use ($order, $installments, $frequency): InstallmentPlan {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (($reason = $this->ineligibility($locked)) !== null) {
                throw ApiException::unprocessable($reason, 'This order cannot be paid in installments.');
            }

            $total = Money::normalize((string) $locked->total);
            $share = Money::round(Money::div($total, (string) $installments), $locked->currency_code);
            // Never above the total: a share rounded up is floored.
            $share = Money::cmp(Money::mul($share, (string) $installments), $total) > 0
                ? bcsub($share, bcdiv('1', bcpow('10', (string) Money::decimals($locked->currency_code)), 4), 4) : $share;
            $today = CarbonImmutable::today();

            $plan = new InstallmentPlan;
            $plan->forceFill([
                'order_id' => $locked->id,
                'total_amount' => $total,
                'currency_code' => $locked->currency_code,
                'number_of_installments' => $installments,
                'frequency' => $frequency,
                'status' => InstallmentPlan::ACTIVE,
                'starts_at' => $today->toDateString(),
            ])->save();

            for ($sequence = 1; $sequence <= $installments; $sequence++) {
                $amount = $sequence < $installments ? $share : Money::sub($total, Money::mul($share, (string) ($installments - 1)));
                $due = match ($frequency) {
                    'weekly' => $today->addWeeks($sequence - 1),
                    'biweekly' => $today->addWeeks(2 * ($sequence - 1)),
                    default => $today->addMonthsNoOverflow($sequence - 1),
                };

                $row = new InstallmentPayment;
                $row->forceFill([
                    'installment_plan_id' => $plan->id,
                    'sequence' => $sequence,
                    'amount_due' => $amount,
                    'due_date' => $due->toDateString(),
                    'status' => InstallmentPayment::PENDING,
                ])->save();
            }

            $locked->forceFill(['payment_expires_at' => null])->save();

            return $plan->load('payments');
        });
    }

    /**
     * A customer-initiated payment of one installment (§47.3 step 2),
     * saving the authorization for the scheduled charges.
     *
     * @return array{checkout_url: string, reference: string}
     */
    public function payInstallment(InstallmentPayment $payment, string $provider, ?string $idempotencyKey = null): array
    {
        $payment->loadMissing('plan.order');
        $this->assertOpen($payment);

        return app(OrderPaymentService::class)->initiateGatewayPayment($payment->plan->order, $provider,
            Money::sub((string) $payment->amount_due, (string) $payment->amount_paid), $idempotencyKey, $payment->id, true);
    }

    /**
     * A scheduled or staff-triggered charge through the stored
     * authorization (§47.3 step 4). The provider is called outside any
     * transaction; the result goes through the normal payment transition.
     */
    public function chargeInstallment(InstallmentPayment $payment): OrderPayment
    {
        $payment->loadMissing('plan.order');
        $this->assertOpen($payment);
        $plan = $payment->plan;

        if ($plan->authorization_token === null || $plan->payment_provider === null) {
            throw ApiException::unprocessable('no_stored_authorization', 'The customer has not saved a payment method yet: they pay this installment themselves.');
        }

        $order = $plan->order;
        $amount = Money::sub((string) $payment->amount_due, (string) $payment->amount_paid);
        $mode = OrderPaymentService::modeOf($order);

        $row = new OrderPayment;
        $row->forceFill([
            'order_id' => $order->id,
            'kind' => OrderPayment::PAYMENT,
            'payment_method' => 'gateway',
            'provider' => $plan->payment_provider,
            'mode' => $mode,
            'status' => OrderPayment::PENDING,
            'reference' => 'INS-'.$order->order_number.'-'.$payment->sequence.'-'.Str::upper(Str::random(6)),
            'amount_due' => $amount,
            'amount_paid' => $amount,
            'currency_code' => $order->currency_code,
            'installment_payment_id' => $payment->id,
            'meta' => ['stored_authorization' => true],
        ])->save();

        try {
            $result = $this->factory->forTenant($plan->payment_provider, $mode)->chargeAuthorization($plan->authorization_token, new ChargeRequest(
                amount: $amount,
                currencyCode: $order->currency_code,
                reference: $row->reference,
                customerEmail: (string) $order->customer_email,
                customerName: $order->customer_name,
                metadata: ['order_number' => $order->order_number, 'installment' => $payment->sequence],
                description: 'Installment '.$payment->sequence.' of order '.$order->order_number,
            ));
        } catch (PaymentGatewayException $e) {
            if (! $e->pending) {
                app(OrderPaymentService::class)->completeGatewayPayment($row, ['status' => 'failed', 'failure_reason' => 'charge_rejected']);
            }

            return $row->refresh();
        }

        if ($result['status'] !== 'pending') {
            app(OrderPaymentService::class)->completeGatewayPayment($row, [
                'status' => $result['status'],
                'provider_reference' => $result['provider_reference'],
                'failure_reason' => $result['failure_reason'],
            ]);
        }

        return $row->refresh();
    }

    /**
     * Called by OrderPaymentService in the payment's transition (§47.3
     * step 3). Under on_first_payment the first paid installment confirms
     * the order; full payment confirms it anyway through the ledger.
     */
    public function handleInstallmentPaid(OrderPayment $ledgerRow, ?string $authorizationToken): void
    {
        /** @var InstallmentPayment $installment */
        $installment = InstallmentPayment::query()->lockForUpdate()->findOrFail($ledgerRow->installment_payment_id);
        /** @var InstallmentPlan $plan */
        $plan = InstallmentPlan::query()->lockForUpdate()->findOrFail($installment->installment_plan_id);

        $paid = Money::add((string) $installment->amount_paid, (string) $ledgerRow->amount_paid);
        $settled = Money::cmp($paid, (string) $installment->amount_due) >= 0;

        $installment->forceFill([
            'amount_paid' => $paid,
            'paid_at' => $settled ? now() : $installment->paid_at,
            'status' => $settled ? InstallmentPayment::PAID : $installment->status,
        ])->save();

        $changes = ['consecutive_overdue_count' => 0];

        if ($authorizationToken !== null && $authorizationToken !== '') {
            $changes['authorization_token'] = $authorizationToken;
            $changes['payment_provider'] = $ledgerRow->provider;
        }

        if (! InstallmentPayment::query()->where('installment_plan_id', $plan->id)->where('status', '!=', InstallmentPayment::PAID)->exists()) {
            $changes['status'] = InstallmentPlan::COMPLETED;
        }

        $plan->forceFill($changes)->save();

        if ($settled && $this->settings->get('installments_fulfillment_policy') === 'on_first_payment') {
            $order = Order::query()->find($plan->order_id);

            if ($order !== null && $order->confirmed_at === null) {
                $this->orders->confirmOrder($order);
            }
        }
    }

    /**
     * A failed gateway attempt (§47.3 step 4): the customer can still pay.
     */
    public function handleInstallmentFailed(OrderPayment $ledgerRow): void
    {
        InstallmentPayment::query()->whereKey($ledgerRow->installment_payment_id)->where('status', InstallmentPayment::PENDING)
            ->update(['status' => InstallmentPayment::FAILED, 'updated_at' => now()]);
    }

    /**
     * ChargeDueInstallments (§47.3 step 4): pending installments due today
     * (or earlier) on active plans with a stored authorization.
     */
    public function chargeDueInstallments(): int
    {
        $charged = 0;

        InstallmentPayment::query()->with('plan.order')
            ->where('status', InstallmentPayment::PENDING)->whereDate('due_date', '<=', today())
            ->whereHas('plan', static fn ($q) => $q->where('status', InstallmentPlan::ACTIVE)->whereNotNull('authorization_token'))
            ->orderBy('id')->get()
            ->each(function (InstallmentPayment $payment) use (&$charged): void {
                if (OrderPayment::query()->where('installment_payment_id', $payment->id)->where('status', OrderPayment::PENDING)->exists()) {
                    return;
                }

                $this->chargeInstallment($payment);
                $charged++;
            });

        return $charged;
    }

    /**
     * MarkOverdueInstallments (§47.3 step 5).
     */
    public function markOverdueInstallments(): int
    {
        $marked = 0;

        InstallmentPayment::query()->whereIn('status', [InstallmentPayment::PENDING, InstallmentPayment::FAILED])
            ->whereDate('due_date', '<', today())
            ->whereHas('plan', static fn ($q) => $q->whereIn('status', [InstallmentPlan::ACTIVE, InstallmentPlan::DEFAULTED]))
            ->orderBy('id')->get()
            ->each(function (InstallmentPayment $payment) use (&$marked): void {
                $this->markOverdue($payment);
                $marked++;
            });

        return $marked;
    }

    public function markOverdue(InstallmentPayment $payment): void
    {
        DB::connection('tenant')->transaction(function () use ($payment): void {
            $updated = InstallmentPayment::query()->whereKey($payment->id)->whereIn('status', [InstallmentPayment::PENDING, InstallmentPayment::FAILED])
                ->update(['status' => InstallmentPayment::OVERDUE, 'updated_at' => now()]);

            if ($updated === 0) {
                return;
            }

            /** @var InstallmentPlan $plan */
            $plan = InstallmentPlan::query()->lockForUpdate()->findOrFail($payment->installment_plan_id);
            $count = $plan->consecutive_overdue_count + 1;
            $plan->forceFill(['consecutive_overdue_count' => $count])->save();

            if ($plan->status === InstallmentPlan::ACTIVE && $count >= (int) $this->settings->get('installments_default_after_overdue_count', 2)) {
                $this->markPlanDefaulted($plan);
            }
        });
    }

    /**
     * A business flag for staff (§47.3 step 5); nothing is cancelled.
     */
    public function markPlanDefaulted(InstallmentPlan $plan): void
    {
        $plan->forceFill(['status' => InstallmentPlan::DEFAULTED])->save();
    }

    /**
     * Stops future charges; payments already made stay on the order (§47.4).
     */
    public function cancelPlan(InstallmentPlan $plan): InstallmentPlan
    {
        if (in_array($plan->status, [InstallmentPlan::COMPLETED, InstallmentPlan::CANCELLED], true)) {
            throw ApiException::invalidTransition($plan->status, InstallmentPlan::CANCELLED);
        }

        $plan->forceFill(['status' => InstallmentPlan::CANCELLED, 'authorization_token' => null])->save();

        return $plan;
    }

    /**
     * The cancellation hook: an order that is cancelled takes its plan with it.
     */
    public function cancelForOrder(Order $order): void
    {
        InstallmentPlan::query()->where('order_id', $order->id)->whereIn('status', [InstallmentPlan::ACTIVE, InstallmentPlan::DEFAULTED])
            ->update(['status' => InstallmentPlan::CANCELLED, 'authorization_token' => null, 'updated_at' => now()]);
    }

    public function getPlanForOrder(Order $order): ?InstallmentPlan
    {
        return InstallmentPlan::query()->with('payments')->where('order_id', $order->id)->first();
    }

    public static function activeFor(int $orderId): bool
    {
        return InstallmentPlan::query()->where('order_id', $orderId)->whereIn('status', [InstallmentPlan::ACTIVE, InstallmentPlan::DEFAULTED])->exists();
    }

    /**
     * @param  array{status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, InstallmentPlan>
     */
    public function listPlans(array $filters = []): LengthAwarePaginator
    {
        return InstallmentPlan::query()->with(['order:id,order_number,customer_name,customer_email,total,currency_code', 'payments'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function assertOpen(InstallmentPayment $payment): void
    {
        if (! in_array($payment->plan->status, [InstallmentPlan::ACTIVE, InstallmentPlan::DEFAULTED], true)) {
            throw ApiException::unprocessable('installment_plan_closed', 'This installment plan is '.$payment->plan->status.'.');
        }

        if (! in_array($payment->status, InstallmentPayment::OPEN, true)) {
            throw ApiException::unprocessable('installment_paid', 'This installment is already paid.');
        }

        if ($payment->plan->order->status === Order::CANCELLED) {
            throw ApiException::unprocessable('order_not_payable', 'This order is cancelled.');
        }
    }
}
