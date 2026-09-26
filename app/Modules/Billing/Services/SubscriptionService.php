<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Services\AffiliateCommissionService;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionMrrMovement;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Plans\Models\TenantFeature;
use App\Modules\Plans\Models\TenantLimitOverride;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Services\ModuleActivationService;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Plans\Services\PlanService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Shared\Activity\ActivityRecorder;
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
 * Gateway-agnostic subscription orchestration (spec §14.3–§14.10).
 *
 * Every money state change is a single row transition under a lock
 * (pending → successful | failed), so duplicated webhooks, verification
 * races and admin retries are harmless. Renewals are charged by the
 * platform through the saved authorization, so the platform's computed
 * cycle total is always what is charged (§14.5).
 */
final readonly class SubscriptionService
{
    public const string PURPOSE_INITIAL = 'initial';

    public const string PURPOSE_RENEWAL = 'renewal';

    public const string PURPOSE_PRORATION = 'proration';

    public function __construct(
        private PlanService $plans,
        private PlatformSettingsService $settings,
        private PlatformPaymentGatewayService $gateways,
        private PaymentGatewayFactory $factory,
        private SubscriptionBillingService $billing,
        private PlatformCouponService $coupons,
        private SubscriptionAccessService $access,
        private FeatureAccessService $features,
        private PlanLimitService $limits,
        private ModuleActivationService $activation,
        private NotificationDispatchService $notifications,
        private AffiliateCommissionService $affiliateCommissions,
    ) {}

    public function getCurrentSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::current((string) $tenant->getTenantKey());
    }

    /**
     * At email verification (§9.3): snapshots the mode and the resolved
     * trial, and reserves the coupon.
     */
    public function createInitialSubscription(Tenant $tenant, PlanPrice $price, ?PlatformCoupon $coupon = null): Subscription
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $price, $coupon): Subscription {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($tenant->getTenantKey())->lockForUpdate()->firstOrFail();
            $trialDays = $this->eligibleTrialDays($tenant, $price);
            $isFree = ! Money::isPositive((string) $price->amount);

            $status = match (true) {
                $trialDays > 0 && ! $price->trial_requires_payment_method => SubscriptionStatus::Trialing,
                $trialDays === 0 && $isFree => SubscriptionStatus::Active,
                default => SubscriptionStatus::Incomplete,
            };

            /** @var Subscription $subscription */
            $subscription = Subscription::query()->create([
                'tenant_id' => $tenant->getTenantKey(),
                'plan_id' => $price->plan_id,
                'plan_price_id' => $price->id,
                'currency_code' => $price->currency_code,
                'billing_interval' => $price->billing_interval,
                'gateway_mode' => (string) $this->settings->get('billing_payment_mode'),
                'status' => $status,
                'trial_days' => $trialDays,
                'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
                'starts_at' => now(),
                'renews_at' => match (true) {
                    $trialDays > 0 => now()->addDays($trialDays),
                    $isFree => now()->addMonths($price->billing_interval === 'yearly' ? 12 : 1),
                    default => null,
                },
            ]);

            if ($trialDays > 0) {
                $tenant->forceFill(['trial_consumed_at' => now()])->save();
            }

            if ($coupon !== null) {
                $this->coupons->reserve($coupon, $subscription, $tenant->email);
            }

            $this->flushTenantCaches($tenant);

            return $subscription;
        });
    }

    /**
     * Starts a checkout for a plan: pays an incomplete or past-due
     * subscription, converts a trial, or resubscribes after cancellation.
     *
     * @return array{checkout_url: string|null, reference: string, subscription: Subscription, status: string}
     */
    public function subscribeTenantToPlan(Tenant $tenant, Plan $plan, string $interval, string $gateway, ?string $couponCode = null): array
    {
        $price = $this->plans->getPriceForTenant($plan, $tenant, $interval);

        $available = $this->gateways->availableFor($tenant, $price->currency_code)->pluck('provider')->all();

        if (! in_array($gateway, $available, true)) {
            throw ApiException::unprocessable('gateway_unavailable', 'This payment provider is not available for your billing currency.', [
                'available' => array_values($available),
            ]);
        }

        [$subscription, $charge] = DB::connection('landlord')->transaction(function () use ($tenant, $price, $gateway, $couponCode): array {
            Tenant::query()->whereKey($tenant->getTenantKey())->lockForUpdate()->firstOrFail();

            $current = $this->getCurrentSubscription($tenant);

            if ($current !== null && $current->status === SubscriptionStatus::Active) {
                throw ApiException::conflict('subscription_active', 'The subscription is active. Use the plan change instead.');
            }

            if (PaymentTransaction::query()->where('tenant_id', $tenant->getTenantKey())->where('type', PaymentTransaction::CHARGE)
                ->where('status', PaymentTransaction::PENDING)->where('created_at', '>', now()->subMinutes(30))->exists()) {
                throw ApiException::conflict('payment_in_progress', 'A payment is already in progress. Complete it or try again shortly.');
            }

            $subscription = $current;

            if ($subscription === null) {
                /** @var Subscription $subscription */
                $subscription = Subscription::query()->create([
                    'tenant_id' => $tenant->getTenantKey(),
                    'plan_id' => $price->plan_id,
                    'plan_price_id' => $price->id,
                    'currency_code' => $price->currency_code,
                    'billing_interval' => $price->billing_interval,
                    'gateway_mode' => (string) $this->settings->get('billing_payment_mode'),
                    'status' => SubscriptionStatus::Incomplete,
                    'trial_days' => 0,
                    'starts_at' => now(),
                ]);
            } elseif ($subscription->status !== SubscriptionStatus::PastDue && $subscription->plan_price_id !== $price->id) {
                // A trial or unpaid subscription may pick another price before paying.
                $subscription->forceFill([
                    'plan_id' => $price->plan_id,
                    'plan_price_id' => $price->id,
                    'currency_code' => $price->currency_code,
                    'billing_interval' => $price->billing_interval,
                ])->save();
            }

            if ($couponCode !== null && $couponCode !== '' && $this->coupons->openRedemption($subscription) === null) {
                $coupon = PlatformCoupon::query()->where('code', strtoupper(trim($couponCode)))->first();

                if ($coupon === null) {
                    throw ApiException::unprocessable('coupon_invalid', 'This coupon cannot be applied.', ['reason' => 'not_found']);
                }

                // Validates under the coupon lock, ignoring this subscription
                // for the first-subscription rule.
                $this->coupons->reserve($coupon, $subscription->setRelation('planPrice', $price), $tenant->email);
            }

            $subscription->forceFill(['gateway' => $gateway])->save();
            $subscription->load(['planPrice.plan', 'tenant']);

            $cycle = $this->billing->calculateCycleTotal($subscription);
            $charge = $this->createCharge($subscription, $gateway, $cycle, self::PURPOSE_INITIAL);

            return [$subscription, $charge];
        });

        // A cycle fully covered by a coupon needs no gateway.
        if (! Money::isPositive((string) $charge->amount)) {
            $this->completeCharge($charge, null, null, null);

            return ['checkout_url' => null, 'reference' => $charge->reference, 'subscription' => $subscription->refresh(), 'status' => 'successful'];
        }

        $result = $this->factory->forPlatform($gateway, $subscription->gateway_mode)->initiateSubscriptionCharge(
            $tenant,
            $subscription->planPrice,
            $subscription,
            $this->chargeRequest($tenant, $charge, true),
        );

        $charge->forceFill(['meta' => array_merge((array) $charge->meta, ['checkout_reference' => $result['provider_reference']])])->save();

        return ['checkout_url' => $result['checkout_url'], 'reference' => $charge->reference, 'subscription' => $subscription, 'status' => 'pending'];
    }

    /**
     * Routes a verified webhook to its handler (spec §15.6 step 5).
     */
    public function handleWebhookEvent(WebhookEvent $event, string $provider, string $mode): void
    {
        match ($event->type) {
            WebhookEvent::CHARGE_SUCCEEDED => $this->handleSuccessfulCharge($event, $provider, $mode),
            WebhookEvent::CHARGE_FAILED => $this->handleFailedCharge($event, $provider, $mode),
            WebhookEvent::REFUND_PROCESSED, WebhookEvent::REFUND_FAILED => $this->handleRefundEvent($event, $provider, $mode),
            WebhookEvent::DISPUTE_OPENED, WebhookEvent::DISPUTE_WON, WebhookEvent::DISPUTE_LOST => $this->handleDispute($event, $provider, $mode),
            default => null,
        };
    }

    public function handleSuccessfulCharge(WebhookEvent $event, string $provider, string $mode): void
    {
        $charge = $this->findCharge($event->reference, $provider, $mode);

        if ($charge === null) {
            return;
        }

        if ($event->amount !== null && ($event->currencyCode !== null && strtoupper($event->currencyCode) !== $charge->currency_code
            || Money::cmp(Money::normalize($event->amount), Money::normalize((string) $charge->amount)) !== 0)) {
            $this->flagMismatch($charge, $event);

            return;
        }

        $this->completeCharge($charge, $event->providerReference, $event->fee, $event->authorizationToken, $event->raw, $event->paymentMethodFingerprint);
    }

    public function handleFailedCharge(WebhookEvent $event, string $provider, string $mode): void
    {
        $charge = $this->findCharge($event->reference, $provider, $mode);

        if ($charge !== null) {
            $this->failCharge($charge, $event->failureReason ?? 'Payment failed', $event->providerReference);
        }
    }

    /**
     * §14.9 step 1. The refund row reserves the amount before the provider
     * is called; the provider result performs the single transition.
     */
    public function refundTransaction(PaymentTransaction $charge, ?string $amount, string $reason, PlatformUser $by): PaymentTransaction
    {
        $refund = DB::connection('landlord')->transaction(function () use ($charge, $amount, $reason, $by): PaymentTransaction {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->type !== PaymentTransaction::CHARGE || $locked->status !== PaymentTransaction::SUCCESSFUL || blank($locked->provider_reference)) {
                throw ApiException::unprocessable('not_refundable', 'Only a successful gateway charge can be refunded.');
            }

            $remaining = $this->refundableAmount($locked);
            $amount = $amount === null ? $remaining : Money::round(Money::normalize($amount), $locked->currency_code);

            if (! Money::isPositive($amount) || Money::cmp($amount, $remaining) > 0) {
                throw ApiException::unprocessable('refund_exceeds_payment', 'The refund exceeds the refundable amount.', ['refundable' => $remaining]);
            }

            /** @var PaymentTransaction $refund */
            $refund = PaymentTransaction::query()->create([
                'tenant_id' => $locked->tenant_id,
                'subscription_id' => $locked->subscription_id,
                'type' => PaymentTransaction::REFUND,
                'mode' => $locked->mode,
                'provider' => $locked->provider,
                'reference' => 'RFD-'.Str::upper((string) Str::ulid()),
                'amount' => Money::sub('0', $amount),
                'currency_code' => $locked->currency_code,
                'status' => PaymentTransaction::PENDING,
                'refund_of_payment_transaction_id' => $locked->id,
                'reason' => $reason,
                'refunded_by' => $by->id,
            ]);

            ActivityRecorder::landlord('billing', "Refund of {$amount} {$locked->currency_code} requested", $refund, ['reason' => $reason], $by);

            return $refund;
        });

        try {
            $result = $this->factory->forPlatform($charge->provider, $charge->mode)
                ->refund((string) $charge->provider_reference, ltrim((string) $refund->amount, '-'), $charge->currency_code, $refund->reference);
        } catch (PaymentGatewayException $e) {
            if (! $e->pending) {
                $this->transitionReversal($refund, PaymentTransaction::FAILED, null, $e->getMessage());
            }

            return $refund->refresh();
        }

        if ($result['refund_reference'] !== null) {
            $refund->forceFill(['provider_reference' => $result['refund_reference']])->save();
        }

        if ($result['status'] !== 'pending') {
            $this->transitionReversal($refund, $result['status'] === 'successful' ? PaymentTransaction::SUCCESSFUL : PaymentTransaction::FAILED);
        }

        return $refund->refresh();
    }

    public function handleRefundEvent(WebhookEvent $event, string $provider, string $mode): void
    {
        $query = PaymentTransaction::query()->where('type', PaymentTransaction::REFUND)->where('provider', $provider)->where('mode', $mode);

        $refund = null;

        if ($event->refundReference !== null) {
            $refund = (clone $query)->where('reference', $event->refundReference)->first();
        }

        if ($refund === null && $event->providerReference !== null) {
            $refund = (clone $query)->where('provider_reference', $event->providerReference)->first();
        }

        if ($refund === null) {
            Log::info('Billing refund webhook without a matching refund row.', ['provider' => $provider, 'mode' => $mode, 'provider_reference' => $event->providerReference]);

            return;
        }

        if ($refund->provider_reference === null && $event->providerReference !== null) {
            $refund->forceFill(['provider_reference' => $event->providerReference])->save();
        }

        $this->transitionReversal($refund, $event->type === WebhookEvent::REFUND_PROCESSED ? PaymentTransaction::SUCCESSFUL : PaymentTransaction::FAILED);
    }

    /**
     * §14.9 step 2: opened reserves the amount, lost makes it successful,
     * won makes it failed.
     */
    public function handleDispute(WebhookEvent $event, string $provider, string $mode): void
    {
        $charge = $this->findCharge($event->reference, $provider, $mode)
            ?? ($event->chargeProviderReference !== null
                ? PaymentTransaction::query()->where('type', PaymentTransaction::CHARGE)->where('provider', $provider)->where('mode', $mode)
                    ->where('provider_reference', $event->chargeProviderReference)->first()
                : null);

        if ($charge === null || $event->providerReference === null) {
            Log::info('Billing dispute webhook without a matching charge.', ['provider' => $provider, 'mode' => $mode]);

            return;
        }

        $reference = 'CBK-'.$provider.'-'.$event->providerReference;

        $chargeback = PaymentTransaction::query()->where('reference', $reference)->first();

        if ($chargeback === null) {
            $amount = $event->amount !== null ? Money::min(Money::normalize($event->amount), $this->refundableAmount($charge)) : $this->refundableAmount($charge);

            /** @var PaymentTransaction $chargeback */
            $chargeback = PaymentTransaction::query()->firstOrCreate(['reference' => $reference], [
                'tenant_id' => $charge->tenant_id,
                'subscription_id' => $charge->subscription_id,
                'type' => PaymentTransaction::CHARGEBACK,
                'mode' => $charge->mode,
                'provider' => $provider,
                'provider_reference' => $event->providerReference,
                'amount' => Money::sub('0', $amount),
                'currency_code' => $charge->currency_code,
                'status' => PaymentTransaction::PENDING,
                'refund_of_payment_transaction_id' => $charge->id,
                'reason' => 'dispute',
            ]);
        }

        match ($event->type) {
            WebhookEvent::DISPUTE_LOST => $this->transitionReversal($chargeback, PaymentTransaction::SUCCESSFUL),
            WebhookEvent::DISPUTE_WON => $this->transitionReversal($chargeback, PaymentTransaction::FAILED),
            default => null,
        };

        // An open dispute holds the affiliate commission for review (§21A.5).
        match ($event->type) {
            WebhookEvent::DISPUTE_OPENED => $this->affiliateCommissions->handleDisputeOpened($charge),
            WebhookEvent::DISPUTE_WON => $this->affiliateCommissions->handleDisputeWon($charge),
            default => null,
        };
    }

    public function cancelTenantSubscription(Tenant $tenant): Subscription
    {
        $subscription = $this->getCurrentSubscription($tenant);

        if ($subscription === null) {
            throw ApiException::unprocessable('no_subscription', 'There is no subscription to cancel.');
        }

        if ($subscription->gateway !== null) {
            $this->factory->forPlatform($subscription->gateway, $subscription->gateway_mode)->cancelSubscription($subscription);
        }

        DB::connection('landlord')->transaction(function () use ($subscription): void {
            $endsAt = match ($subscription->status) {
                SubscriptionStatus::Incomplete, SubscriptionStatus::PastDue => now(),
                SubscriptionStatus::Trialing => $subscription->trial_ends_at ?? now(),
                default => $subscription->renews_at ?? now(),
            };

            $subscription->forceFill([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'ends_at' => $endsAt,
                'scheduled_plan_id' => null,
                'scheduled_plan_price_id' => null,
            ])->save();

            $redemption = $this->coupons->openRedemption($subscription);

            if ($redemption !== null) {
                $redemption->status === 'reserved' ? $this->coupons->release($redemption) : $this->coupons->complete($redemption);
            }
        });

        $tenant = $subscription->tenant;
        $this->flushTenantCaches($tenant);

        $this->notifications->dispatch('subscription.cancelled', $tenant, [
            'owner_name' => $tenant->owner_name,
            'plan_name' => $subscription->plan->name,
            'ends_at' => $subscription->ends_at?->toDayDateTimeString() ?? '',
        ]);

        return $subscription;
    }

    /**
     * §11.11 impact preview.
     *
     * @return array{direction: string, effective_at: string|null, modules_locked: list<string>, modules_unlocked: list<string>, limits_exceeded: list<array{key: string, used: int, new_limit: int}>, addons_no_longer_billed: list<string>}
     */
    public function previewPlanChange(Tenant $tenant, Plan $newPlan, ?string $interval = null): array
    {
        $subscription = $this->requireChangeable($tenant);
        $interval ??= $subscription->billing_interval;
        $newPrice = $this->plans->getPriceForTenant($newPlan, $tenant, $interval);
        $direction = $this->direction($subscription, $newPrice);

        $newFeatures = $newPlan->features()->pluck('feature_key')->all();
        $modulesLocked = [];
        $modulesUnlocked = [];

        foreach ($this->features->listEffectiveModules($tenant) as $module) {
            $viaOverride = $module['source'] === 'override';
            $willBeEntitled = $viaOverride ? $module['entitled'] : in_array($module['key'], $newFeatures, true);

            if ($module['entitled'] && ! $willBeEntitled && $module['activation'] !== null) {
                $modulesLocked[] = $module['key'];
            }

            if (! $module['entitled'] && $willBeEntitled) {
                $modulesUnlocked[] = $module['key'];
            }
        }

        $limitsExceeded = [];
        $newLimits = $newPlan->limits()->pluck('limit_value', 'limit_key');

        foreach ($this->limits->getUsageSummary($tenant) as $key => $usage) {
            $hasOverride = TenantLimitOverride::query()
                ->where('tenant_id', $tenant->getTenantKey())->where('limit_key', $key)->where('is_active', true)->exists();
            $newLimit = $newLimits->get($key);

            if (! $hasOverride && $usage['used'] !== null && $newLimit !== null && $usage['used'] > (int) $newLimit) {
                $limitsExceeded[] = ['key' => $key, 'used' => $usage['used'], 'new_limit' => (int) $newLimit];
            }
        }

        $addons = TenantFeature::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->where('billed', true)
            ->whereNotNull('extra_amount')
            ->whereIn('feature_key', $newFeatures)
            ->pluck('feature_key')
            ->all();

        return [
            'direction' => $direction,
            'effective_at' => $this->appliesImmediately($subscription, $direction) ? now()->toIso8601String() : $subscription->renews_at?->toIso8601String(),
            'modules_locked' => $modulesLocked,
            'modules_unlocked' => $modulesUnlocked,
            'limits_exceeded' => $limitsExceeded,
            'addons_no_longer_billed' => $addons,
        ];
    }

    /**
     * §14.4: downgrades and interval changes wait for the renewal; upgrades
     * apply now (prorated) under proration_mode = immediate.
     */
    public function swapTenantPlan(Tenant $tenant, Plan $newPlan, ?string $interval = null, bool $confirmImpact = false): Subscription
    {
        $subscription = $this->requireChangeable($tenant);
        $interval ??= $subscription->billing_interval;
        $newPrice = $this->plans->getPriceForTenant($newPlan, $tenant, $interval);

        if ($newPrice->id === $subscription->plan_price_id) {
            throw ApiException::unprocessable('plan_unchanged', 'The subscription is already on this plan and interval.');
        }

        $preview = $this->previewPlanChange($tenant, $newPlan, $interval);

        if (! $confirmImpact && ($preview['modules_locked'] !== [] || $preview['limits_exceeded'] !== [])) {
            throw ApiException::unprocessable('plan_change_requires_confirmation', 'Confirm the impact of this plan change.', $preview);
        }

        $direction = $preview['direction'];
        $previousPlan = $subscription->plan;

        if (! $this->appliesImmediately($subscription, $direction)) {
            $subscription->forceFill(['scheduled_plan_id' => $newPlan->id, 'scheduled_plan_price_id' => $newPrice->id])->save();
        } elseif ($subscription->status === SubscriptionStatus::Trialing) {
            $this->applyPlanChange($subscription, $newPrice);

            if ($this->plans->resolveTrialDays($newPrice) === 0) {
                // The trial ends; the next renewal run charges the new price.
                $subscription->forceFill(['trial_ends_at' => now(), 'renews_at' => now()])->save();
            }
        } else {
            $this->chargeProration($subscription, $newPrice);
        }

        $this->notifications->dispatch($direction === 'upgrade' ? 'subscription.plan_upgraded' : 'subscription.plan_downgraded', $tenant, [
            'owner_name' => $tenant->owner_name,
            'previous_plan_name' => $previousPlan->name,
            'plan_name' => $newPlan->name,
            'effective_at' => $preview['effective_at'] ?? '',
            'locked_modules' => implode(', ', $preview['modules_locked']) ?: 'none',
            'exceeded_limits' => implode(', ', array_column($preview['limits_exceeded'], 'key')) ?: 'none',
        ]);

        return $subscription->refresh();
    }

    public function extendTrial(Subscription $subscription, int $days, string $reason, PlatformUser $by): Subscription
    {
        if ($days < 1 || $days > 365) {
            throw ApiException::unprocessable('validation_failed', 'Extend by 1 to 365 days.');
        }

        $graceDays = (int) $this->settings->get('past_due_grace_days', 7);
        $withinGrace = $subscription->status === SubscriptionStatus::PastDue
            && $subscription->trial_ends_at !== null
            && ! PaymentTransaction::query()->where('subscription_id', $subscription->id)->where('type', PaymentTransaction::CHARGE)->where('status', PaymentTransaction::SUCCESSFUL)->exists()
            && ($subscription->past_due_at ?? now())->copy()->addDays($graceDays)->isFuture();

        if ($subscription->status !== SubscriptionStatus::Trialing && ! $withinGrace) {
            throw ApiException::invalidTransition($subscription->status->value, 'trialing');
        }

        $base = $subscription->trial_ends_at !== null && $subscription->trial_ends_at->isFuture() ? $subscription->trial_ends_at : now();
        $endsAt = $base->copy()->addDays($days);

        $subscription->forceFill([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $endsAt,
            'renews_at' => $endsAt,
            'past_due_at' => null,
        ])->save();

        ActivityRecorder::landlord('billing', "Trial extended by {$days} days", $subscription, ['reason' => $reason, 'trial_ends_at' => $endsAt->toIso8601String()], $by);
        $this->flushTenantCaches($subscription->tenant);

        return $subscription;
    }

    /**
     * Charges the renewal of a subscription whose period has ended, through
     * the saved authorization (§14.7, ProcessSubscriptionRenewal). The
     * reference is fixed per period, so a rerun never charges twice.
     */
    public function renew(Subscription $subscription): ?PaymentTransaction
    {
        $subscription->loadMissing(['tenant', 'planPrice.plan']);
        $periodEnd = $subscription->renews_at ?? $subscription->trial_ends_at;

        if ($periodEnd === null || $periodEnd->isFuture()) {
            return null;
        }

        $reference = 'REN-'.$subscription->id.'-'.$periodEnd->format('YmdHis');
        $existing = PaymentTransaction::query()->where('reference', $reference)->first();

        if ($existing !== null) {
            if ($existing->status === PaymentTransaction::PENDING) {
                $this->verifyPendingCharge($existing);
            }

            return $existing->refresh();
        }

        if ($subscription->authorization_reference === null || $subscription->gateway === null) {
            $this->markPastDue($subscription, 'No payment method on file');

            return null;
        }

        $price = $subscription->scheduled_plan_price_id !== null
            ? PlanPrice::query()->with('plan')->findOrFail($subscription->scheduled_plan_price_id)
            : $subscription->planPrice;

        $cycle = $this->billing->calculateCycleTotal($subscription, $price);
        $charge = $this->createCharge($subscription, $subscription->gateway, $cycle, self::PURPOSE_RENEWAL, $reference, [
            'period_end' => $periodEnd->toIso8601String(),
            'plan_price_id' => $price->id,
        ]);

        if (! Money::isPositive((string) $charge->amount)) {
            $this->completeCharge($charge, null, null, null);

            return $charge->refresh();
        }

        $this->chargeSaved($subscription, $charge);

        return $charge->refresh();
    }

    /**
     * Resolves a charge that stayed pending (lost webhook, timeout).
     */
    public function verifyPendingCharge(PaymentTransaction $charge): void
    {
        if ($charge->status !== PaymentTransaction::PENDING) {
            return;
        }

        try {
            $result = $this->factory->forPlatform($charge->provider, $charge->mode)->verifyTransaction($charge->reference);
        } catch (PaymentGatewayException) {
            return;
        }

        if ($result['status'] === 'successful') {
            if ($result['amount'] !== null && Money::cmp(Money::normalize($result['amount']), Money::normalize((string) $charge->amount)) !== 0) {
                Log::error('Billing charge amount mismatch on verification.', ['reference' => $charge->reference]);

                return;
            }

            $this->completeCharge($charge, $result['provider_reference'], $result['fee'], $result['authorization_token']);
        } elseif ($result['status'] === 'failed' && $charge->created_at !== null && $charge->created_at->lt(now()->subHour())) {
            $this->failCharge($charge, 'Payment was not completed');
        }
    }

    public function markPastDue(Subscription $subscription, string $reason): void
    {
        if (in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Cancelled, SubscriptionStatus::Incomplete], true)) {
            return;
        }

        $subscription->forceFill(['status' => SubscriptionStatus::PastDue, 'past_due_at' => now()])->save();
        $this->flushTenantCaches($subscription->tenant);

        $this->notifications->dispatch('subscription.payment_failed', $subscription->tenant, [
            'owner_name' => $subscription->tenant->owner_name,
            'plan_name' => $subscription->plan->name,
            'amount' => $this->formatMoney($this->billing->calculateCycleTotal($subscription)['total'], $subscription->currency_code),
            'failure_reason' => $reason,
            'grace_ends_at' => now()->addDays((int) $this->settings->get('past_due_grace_days', 7))->toFormattedDateString(),
        ]);
    }

    /**
     * Appends an MRR movement for a live subscription when the normalised
     * amount changes (§14.10).
     */
    public function recordMrrMovement(Subscription $subscription, string $before, string $after, string $reason, ?string $type = null): void
    {
        if (! $subscription->isLive()) {
            return;
        }

        $delta = Money::sub($after, $before);

        if (Money::cmp($delta, '0') === 0 && $type === null) {
            return;
        }

        SubscriptionMrrMovement::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'type' => $type ?? (Money::isPositive($delta) ? 'expansion' : 'contraction'),
            'currency_code' => $subscription->currency_code,
            'mrr_before' => $before,
            'mrr_after' => $after,
            'mrr_delta' => $delta,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    /**
     * The tenant's current MRR contribution from the movement ledger.
     */
    public function currentMrr(Subscription $subscription): string
    {
        return Money::normalize((string) (SubscriptionMrrMovement::query()->where('subscription_id', $subscription->id)
            ->orderByDesc('id')->value('mrr_after') ?? '0'));
    }

    // ----------------------------------------------------------------------

    /**
     * The pending → successful transition of a charge and all its effects,
     * under a lock on the tenant row (§14.1, §14.7).
     *
     * @param  array<string, mixed>  $raw
     * @param  string|null  $fingerprint  the provider's payment-method fingerprint, stored hashed for the affiliate fraud rules (§21A.7)
     */
    private function completeCharge(PaymentTransaction $charge, ?string $providerReference, ?string $fee, ?string $authorizationToken, array $raw = [], ?string $fingerprint = null): void
    {
        $effects = DB::connection('landlord')->transaction(function () use ($charge, $providerReference, $fee, $authorizationToken, $raw, $fingerprint): ?array {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($charge->tenant_id)->lockForUpdate()->firstOrFail();
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentTransaction::PENDING) {
                return null;
            }

            $isPaid = Money::isPositive((string) $locked->amount);
            $isFirstPaid = $isPaid && $locked->mode === 'live' && ! PaymentTransaction::query()
                ->where('tenant_id', $tenant->getTenantKey())
                ->where('type', PaymentTransaction::CHARGE)
                ->where('mode', 'live')
                ->where('status', PaymentTransaction::SUCCESSFUL)
                ->where('amount', '>', 0)
                ->exists();

            $meta = $raw === [] ? (array) $locked->meta : array_merge((array) $locked->meta, ['provider' => array_intersect_key($raw, array_flip(['id', 'event', 'type']))]);

            if ($fingerprint !== null && $fingerprint !== '') {
                $meta['payment_method_fingerprint'] = hash('sha256', $locked->provider.'|'.$fingerprint);
            }

            $locked->forceFill([
                'status' => PaymentTransaction::SUCCESSFUL,
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                'fee' => $fee,
                'paid_at' => now(),
                'is_first_paid_charge' => $isFirstPaid,
                'meta' => $meta === [] ? null : $meta,
            ])->save();

            /** @var Subscription $subscription */
            $subscription = Subscription::query()->with(['planPrice.plan'])->whereKey($locked->subscription_id)->lockForUpdate()->firstOrFail();
            $purpose = (string) ($locked->meta['purpose'] ?? self::PURPOSE_INITIAL);
            $wasStatus = $subscription->status;
            $mrrBefore = $this->currentMrr($subscription);
            $planChanged = false;

            if ($authorizationToken !== null) {
                $subscription->authorization_reference = $authorizationToken;
            }

            $subscription->gateway ??= $locked->provider;

            if ($purpose === self::PURPOSE_PRORATION) {
                $this->applyPlanChange($subscription, PlanPrice::query()->with('plan')->findOrFail((int) $locked->meta['plan_price_id']));
                $planChanged = true;
            } else {
                if ($purpose === self::PURPOSE_RENEWAL && $subscription->scheduled_plan_price_id !== null) {
                    $this->applyPlanChange($subscription, PlanPrice::query()->with('plan')->findOrFail($subscription->scheduled_plan_price_id));
                    $planChanged = true;
                }

                $periodStart = $purpose === self::PURPOSE_RENEWAL && $subscription->renews_at !== null ? $subscription->renews_at->copy() : now();

                $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'past_due_at' => null,
                    'renews_at' => $periodStart->addMonths($subscription->intervalMonths()),
                ]);
            }

            $subscription->save();

            $redemption = $this->coupons->openRedemption($subscription);

            if ($redemption !== null) {
                $this->coupons->applyCycle($redemption, $locked);
            }

            // In this transaction, so a commission is never lost or doubled (§21A.5).
            if ($isFirstPaid) {
                $this->affiliateCommissions->recordQualifyingPayment($locked);
            }

            if ($tenant->status === TenantStatus::AwaitingPayment) {
                $tenant->forceFill(['status' => TenantStatus::Provisioning])->save();

                ProvisionTenantDatabase::dispatch(
                    (string) $tenant->getTenantKey(),
                    TenantRegistration::query()->where('tenant_id', $tenant->getTenantKey())->value('id'),
                )->afterCommit();
            }

            $mrrAfter = $isPaid ? $this->billing->monthlyRecurringAmount($subscription) : $mrrBefore;

            if ($isPaid) {
                $churned = Money::cmp($mrrBefore, '0') === 0 && SubscriptionMrrMovement::query()->where('tenant_id', $tenant->getTenantKey())->where('type', 'churn')->exists();

                $this->recordMrrMovement($subscription, $mrrBefore, $mrrAfter, match (true) {
                    $isFirstPaid => 'first_payment',
                    $churned => 'reactivated',
                    $planChanged => 'plan_change',
                    default => 'renewal',
                }, match (true) {
                    $isFirstPaid => 'new',
                    $churned => 'reactivation',
                    default => null,
                });
            }

            return [
                'tenant' => $tenant,
                'subscription' => $subscription,
                'charge' => $locked,
                'renewal' => $purpose === self::PURPOSE_RENEWAL && $wasStatus !== SubscriptionStatus::Incomplete,
                'plan_changed' => $planChanged || $wasStatus === SubscriptionStatus::Incomplete,
            ];
        });

        if ($effects === null) {
            return;
        }

        /** @var Tenant $tenant */
        $tenant = $effects['tenant'];
        $this->flushTenantCaches($tenant);

        if ($effects['plan_changed']) {
            $this->activation->syncAutoModules($tenant);
        }

        if (Money::isPositive((string) $effects['charge']->amount)) {
            $this->notifications->dispatch($effects['renewal'] ? 'subscription.renewed' : 'subscription.payment_succeeded', $tenant, [
                'owner_name' => $tenant->owner_name,
                'plan_name' => $effects['subscription']->plan->name,
                'amount' => $this->formatMoney((string) $effects['charge']->amount, $effects['charge']->currency_code),
                'reference' => $effects['charge']->reference,
                'renews_at' => $effects['subscription']->renews_at?->toFormattedDateString() ?? '',
            ]);
        }
    }

    private function failCharge(PaymentTransaction $charge, string $reason, ?string $providerReference = null): void
    {
        $subscription = DB::connection('landlord')->transaction(function () use ($charge, $reason, $providerReference): ?Subscription {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentTransaction::PENDING) {
                return null;
            }

            $locked->forceFill([
                'status' => PaymentTransaction::FAILED,
                'failure_reason' => mb_substr($reason, 0, 255),
                'provider_reference' => $locked->provider_reference ?? $providerReference,
            ])->save();

            return ($locked->meta['purpose'] ?? null) === self::PURPOSE_RENEWAL ? $locked->subscription : null;
        });

        if ($subscription !== null) {
            $this->markPastDue($subscription, $reason);
        }
    }

    /**
     * The single pending → successful | failed transition of a refund or
     * chargeback row (§14.9 step 5).
     */
    private function transitionReversal(PaymentTransaction $reversal, string $status, ?string $providerReference = null, ?string $failureReason = null): void
    {
        DB::connection('landlord')->transaction(function () use ($reversal, $status, $providerReference, $failureReason): void {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->whereKey($reversal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentTransaction::PENDING) {
                return;
            }

            $locked->forceFill([
                'status' => $status,
                'provider_reference' => $locked->provider_reference ?? $providerReference,
                'failure_reason' => $failureReason !== null ? mb_substr($failureReason, 0, 255) : null,
                'paid_at' => $status === PaymentTransaction::SUCCESSFUL ? now() : null,
            ])->save();

            ActivityRecorder::landlord('billing', ucfirst($locked->type)." {$locked->reference} {$status}", $locked, [
                'amount' => (string) $locked->amount,
                'currency_code' => $locked->currency_code,
            ]);

            if ($status === PaymentTransaction::SUCCESSFUL) {
                $this->affiliateCommissions->handlePaymentReversal($locked);
            }
        });
    }

    /**
     * @param  array{lines: list<array<string, string>>, total: string, currency_code: string}  $cycle
     * @param  array<string, mixed>  $meta
     */
    private function createCharge(Subscription $subscription, string $provider, array $cycle, string $purpose, ?string $reference = null, array $meta = []): PaymentTransaction
    {
        /** @var PaymentTransaction $charge */
        $charge = PaymentTransaction::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'type' => PaymentTransaction::CHARGE,
            'mode' => $subscription->gateway_mode,
            'provider' => $provider,
            'reference' => $reference ?? 'SUB-'.Str::upper((string) Str::ulid()),
            'amount' => $cycle['total'],
            'currency_code' => $cycle['currency_code'],
            'status' => PaymentTransaction::PENDING,
            'line_items' => $cycle['lines'],
            'meta' => ['purpose' => $purpose] + $meta,
        ]);

        return $charge;
    }

    /**
     * Charges a pending row through the saved authorization and applies a
     * definitive result at once; "pending" waits for the webhook.
     */
    private function chargeSaved(Subscription $subscription, PaymentTransaction $charge): void
    {
        try {
            $result = $this->factory->forPlatform((string) $subscription->gateway, $subscription->gateway_mode)
                ->chargeAuthorization((string) $subscription->authorization_reference, $this->chargeRequest($subscription->tenant, $charge, false));
        } catch (PaymentGatewayException $e) {
            if (! $e->pending) {
                $this->failCharge($charge, $e->getMessage());
            }

            return;
        }

        match ($result['status']) {
            'successful' => $this->completeCharge($charge, $result['provider_reference'], null, null),
            'failed' => $this->failCharge($charge, (string) ($result['failure_reason'] ?? 'Payment declined'), $result['provider_reference']),
            default => null,
        };
    }

    /**
     * An immediate upgrade of an active subscription: a prorated,
     * undiscounted charge for the rest of the cycle (§14.4).
     */
    private function chargeProration(Subscription $subscription, PlanPrice $newPrice): void
    {
        if ($subscription->authorization_reference === null || $subscription->gateway === null) {
            throw ApiException::unprocessable('payment_method_required', 'Pay for the subscription once so that upgrades can be charged.');
        }

        $current = $this->billing->calculateCycleTotal($subscription, null, false)['total'];
        $next = $this->billing->calculateCycleTotal($subscription, $newPrice, false)['total'];
        $difference = Money::sub($next, $current);

        $cycleStart = $subscription->renews_at?->copy()->subMonths($subscription->intervalMonths()) ?? now();
        $cycleSeconds = max(1, (int) $cycleStart->diffInSeconds($subscription->renews_at ?? now()->addMonth()));
        $remainingSeconds = max(0, (int) now()->diffInSeconds($subscription->renews_at ?? now(), false));
        $amount = Money::round(Money::div(Money::mul($difference, (string) $remainingSeconds), (string) $cycleSeconds), $subscription->currency_code);

        if (! Money::isPositive($amount)) {
            $this->applyPlanChange($subscription, $newPrice);

            return;
        }

        $charge = $this->createCharge($subscription, $subscription->gateway, [
            'lines' => [[
                'type' => 'proration',
                'key' => $newPrice->plan->slug,
                'label' => 'Upgrade to '.$newPrice->plan->name.' for the rest of the cycle',
                'amount' => $amount,
            ]],
            'total' => $amount,
            'currency_code' => $subscription->currency_code,
        ], self::PURPOSE_PRORATION, null, ['plan_price_id' => $newPrice->id]);

        $this->chargeSaved($subscription, $charge);

        if ($charge->refresh()->status === PaymentTransaction::FAILED) {
            throw ApiException::unprocessable('payment_failed', 'The upgrade could not be charged: '.$charge->failure_reason);
        }
    }

    /**
     * Switches plan and price; the coupon continues only if the new price
     * still qualifies; add-ons the new plan includes stop being billed.
     */
    private function applyPlanChange(Subscription $subscription, PlanPrice $price): void
    {
        $subscription->forceFill([
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'currency_code' => $price->currency_code,
            'billing_interval' => $price->billing_interval,
            'scheduled_plan_id' => null,
            'scheduled_plan_price_id' => null,
        ])->save();
        $subscription->setRelation('planPrice', $price);
        $subscription->unsetRelation('plan');

        $redemption = $this->coupons->openRedemption($subscription);

        if ($redemption !== null) {
            $coupon = $redemption->coupon()->with('targets')->first();
            $qualifies = $coupon !== null && ($coupon->targets->isEmpty() || $coupon->targets->contains(
                static fn ($t): bool => ($t->target_type === 'plan_price' && $t->target_id === $price->id) || ($t->target_type === 'plan' && $t->target_id === $price->plan_id),
            )) && ($coupon->currency_code === null || $coupon->currency_code === $price->currency_code);

            if (! $qualifies) {
                $this->coupons->complete($redemption);
            }
        }

        $included = $price->plan->features()->pluck('feature_key')->all();

        $addons = TenantFeature::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('billed', true)
            ->whereNotNull('extra_amount')
            ->whereIn('feature_key', $included)
            ->get();

        foreach ($addons as $addon) {
            $addon->forceFill(['billed' => false])->save();
            ActivityRecorder::landlord('billing', "Add-on {$addon->feature_key} no longer billed: included in {$price->plan->name}", $addon, [
                'tenant_id' => $subscription->tenant_id,
            ]);
        }
    }

    private function eligibleTrialDays(Tenant $tenant, PlanPrice $price): int
    {
        $days = $this->plans->resolveTrialDays($price);

        if ($days === 0) {
            return 0;
        }

        $firstSubscription = ! Subscription::query()->where('tenant_id', $tenant->getTenantKey())->exists();
        $emailUsedTrial = Tenant::query()
            ->whereRaw('lower(email) = ?', [strtolower($tenant->email)])
            ->where('id', '!=', $tenant->getTenantKey())
            ->whereNotNull('trial_consumed_at')
            ->exists();

        return $firstSubscription && ! $emailUsedTrial && $tenant->trial_consumed_at === null ? $days : 0;
    }

    private function requireChangeable(Tenant $tenant): Subscription
    {
        $subscription = $this->getCurrentSubscription($tenant);

        if ($subscription === null || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
            throw ApiException::unprocessable('subscription_not_changeable', 'Only an active or trialing subscription can change plan.');
        }

        return $subscription->loadMissing(['planPrice.plan', 'plan', 'tenant']);
    }

    private function direction(Subscription $subscription, PlanPrice $newPrice): string
    {
        if ($newPrice->plan_id === $subscription->plan_id) {
            return 'interval_change';
        }

        $current = Money::div((string) $subscription->planPrice->amount, (string) $subscription->intervalMonths());
        $next = Money::div((string) $newPrice->amount, $newPrice->billing_interval === 'yearly' ? '12' : '1');

        return Money::cmp($next, $current) > 0 ? 'upgrade' : 'downgrade';
    }

    private function appliesImmediately(Subscription $subscription, string $direction): bool
    {
        return $direction === 'upgrade'
            && ($subscription->status === SubscriptionStatus::Trialing || $this->settings->get('proration_mode') === 'immediate');
    }

    private function findCharge(?string $reference, string $provider, string $mode): ?PaymentTransaction
    {
        if ($reference === null) {
            return null;
        }

        return PaymentTransaction::query()
            ->where('reference', $reference)
            ->where('provider', $provider)
            ->where('mode', $mode)
            ->whereIn('type', [PaymentTransaction::CHARGE, PaymentTransaction::AUTHORIZATION])
            ->first();
    }

    private function refundableAmount(PaymentTransaction $charge): string
    {
        $reversed = PaymentTransaction::query()
            ->where('refund_of_payment_transaction_id', $charge->id)
            ->whereIn('status', [PaymentTransaction::PENDING, PaymentTransaction::SUCCESSFUL])
            ->pluck('amount')
            ->reduce(static fn (string $sum, $amount): string => Money::add($sum, ltrim((string) $amount, '-')), '0');

        return Money::sub(Money::normalize((string) $charge->amount), $reversed);
    }

    private function flagMismatch(PaymentTransaction $charge, WebhookEvent $event): void
    {
        $charge->forceFill(['meta' => array_merge((array) $charge->meta, [
            'needs_review' => 'amount_mismatch',
            'reported_amount' => $event->amount,
            'reported_currency' => $event->currencyCode,
        ])])->save();

        Log::error('Billing charge amount or currency mismatch; left pending for review.', ['reference' => $charge->reference]);
    }

    private function chargeRequest(Tenant $tenant, PaymentTransaction $charge, bool $saveAuthorization): ChargeRequest
    {
        return new ChargeRequest(
            amount: Money::normalize((string) $charge->amount),
            currencyCode: $charge->currency_code,
            reference: $charge->reference,
            customerEmail: $tenant->email,
            customerName: $tenant->owner_name,
            callbackUrl: FrontendUrl::tenantAdmin($tenant, '/billing/callback', ['reference' => $charge->reference]),
            metadata: ['tenant_id' => (string) $tenant->getTenantKey(), 'reference' => $charge->reference],
            saveAuthorization: $saveAuthorization,
            description: (string) $this->settings->get('platform_name').' subscription',
        );
    }

    private function flushTenantCaches(Tenant $tenant): void
    {
        $this->access->flush($tenant);
        $this->features->flush($tenant);
        $this->limits->flush($tenant);
    }

    private function formatMoney(string $amount, string $currency): string
    {
        return Money::format($amount, $currency);
    }
}
