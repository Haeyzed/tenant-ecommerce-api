<?php

declare(strict_types=1);

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionMrrMovement;
use App\Modules\Billing\Services\SubscriptionBillingService;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily billing run (spec §14.7, §72.2): renews due subscriptions
 * through the saved authorization, resolves stale pending charges, sends
 * advance notices and records churn. Each subscription is isolated: one
 * failure never stops the run.
 */
final class ProcessSubscriptionRenewal implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 900];

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    private const int TRIAL_NOTICE_DAYS = 3;

    private const int RENEWAL_NOTICE_DAYS = 7;

    public function __construct()
    {
        $this->onQueue('landlord-default');
    }

    public function handle(
        SubscriptionService $subscriptions,
        SubscriptionBillingService $billing,
        PlatformSettingsService $settings,
        NotificationDispatchService $notifications,
    ): void {
        $this->eachSubscription(
            Subscription::query()
                ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
                ->where('renews_at', '<=', now()),
            static fn (Subscription $s) => $subscriptions->renew($s),
        );

        $this->eachPending(static fn (PaymentTransaction $charge) => $subscriptions->verifyPendingCharge($charge));

        $this->eachSubscription(
            Subscription::query()->where('status', SubscriptionStatus::Trialing->value)
                ->whereBetween('trial_ends_at', [now(), now()->addDays(self::TRIAL_NOTICE_DAYS)]),
            function (Subscription $s) use ($notifications): void {
                if ($this->once('trial-ending:'.$s->id.':'.$s->trial_ends_at?->getTimestamp())) {
                    $notifications->dispatch('subscription.trial_ending_soon', $s->tenant, [
                        'owner_name' => $s->tenant->owner_name,
                        'plan_name' => $s->plan->name,
                        'trial_ends_at' => $s->trial_ends_at?->toFormattedDateString() ?? '',
                    ]);
                }
            },
        );

        $this->eachSubscription(
            Subscription::query()->where('status', SubscriptionStatus::Active->value)
                ->whereBetween('renews_at', [now(), now()->addDays(self::RENEWAL_NOTICE_DAYS)]),
            function (Subscription $s) use ($notifications, $billing): void {
                if ($this->once('renewing:'.$s->id.':'.$s->renews_at?->getTimestamp())) {
                    $notifications->dispatch('subscription.renewing_soon', $s->tenant, [
                        'owner_name' => $s->tenant->owner_name,
                        'plan_name' => $s->plan->name,
                        'renews_at' => $s->renews_at?->toFormattedDateString() ?? '',
                        'amount' => Money::format($billing->calculateCycleTotal($s)['total'], $s->currency_code),
                    ]);
                }
            },
        );

        $graceDays = (int) $settings->get('past_due_grace_days', 7);

        $this->eachSubscription(
            Subscription::query()->where('gateway_mode', 'live')->where(static fn ($q) => $q
                ->where(static fn ($q) => $q->where('status', SubscriptionStatus::Cancelled->value)->where('ends_at', '<=', now()))
                ->orWhere(static fn ($q) => $q->where('status', SubscriptionStatus::PastDue->value)->where('past_due_at', '<=', now()->subDays($graceDays)))),
            static function (Subscription $s) use ($subscriptions): void {
                $before = $subscriptions->currentMrr($s);

                if (Money::isPositive($before) && ! SubscriptionMrrMovement::query()->where('subscription_id', $s->id)->where('type', 'churn')->exists()) {
                    $subscriptions->recordMrrMovement($s, $before, '0.0000', $s->status === SubscriptionStatus::Cancelled ? 'cancellation_effective' : 'past_due_grace_expired', 'churn');
                }
            },
        );
    }

    /**
     * @param  Builder<Subscription>  $query
     * @param  callable(Subscription): mixed  $callback
     */
    private function eachSubscription($query, callable $callback): void
    {
        $query->with(['tenant', 'plan', 'planPrice.plan'])->chunkById(100, static function ($subscriptions) use ($callback): void {
            foreach ($subscriptions as $subscription) {
                try {
                    $callback($subscription);
                } catch (Throwable $e) {
                    report($e);
                    Log::error('Subscription billing run failed for one subscription.', ['subscription_id' => $subscription->id]);
                }
            }
        });
    }

    /**
     * Charges without a definitive outcome after 15 minutes.
     *
     * @param  callable(PaymentTransaction): mixed  $callback
     */
    private function eachPending(callable $callback): void
    {
        PaymentTransaction::query()
            ->where('type', PaymentTransaction::CHARGE)
            ->where('status', PaymentTransaction::PENDING)
            ->where('created_at', '<=', now()->subMinutes(15))
            ->where('created_at', '>=', now()->subDays(7))
            ->chunkById(100, static function ($charges) use ($callback): void {
                foreach ($charges as $charge) {
                    try {
                        $callback($charge);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
    }

    private function once(string $key): bool
    {
        return Cache::store('landlord')->add('billing-notice:'.$key, true, now()->addDays(40));
    }
}
