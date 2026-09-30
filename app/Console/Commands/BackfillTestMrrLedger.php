<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionMrrMovement;
use App\Modules\Billing\Services\SubscriptionBillingService;
use App\Shared\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-mode subscriptions paid before the MRR ledger recorded test
 * billing have no movement. This adds each one's "new" movement at its
 * first paid test charge, so test figures (§22.1 `mode=test`) include them.
 * Subscriptions that already have a movement are skipped, so it is safe to
 * run again. Live subscriptions are never touched.
 */
#[Signature('billing:backfill-test-mrr {--dry-run : List what would be added without writing}')]
#[Description('Add missing MRR ledger movements for paid test-mode subscriptions')]
final class BackfillTestMrrLedger extends Command
{
    public function handle(SubscriptionBillingService $billing): int
    {
        $added = 0;

        Subscription::query()
            ->with('planPrice.plan')
            ->where('gateway_mode', 'test')
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->whereNotExists(static fn ($q) => $q->from('subscription_mrr_movements')->whereColumn('subscription_mrr_movements.subscription_id', 'subscriptions.id'))
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($billing, &$added): void {
                foreach ($subscriptions as $subscription) {
                    /** @var Subscription $subscription */
                    $firstPaid = PaymentTransaction::query()
                        ->where('tenant_id', $subscription->tenant_id)
                        ->where('type', PaymentTransaction::CHARGE)
                        ->where('mode', 'test')
                        ->where('status', PaymentTransaction::SUCCESSFUL)
                        ->where('amount', '>', 0)
                        ->orderBy('paid_at')
                        ->first();

                    if ($firstPaid === null || $firstPaid->paid_at === null) {
                        continue;
                    }

                    $mrr = Money::normalize($billing->monthlyRecurringAmount($subscription));
                    $this->line("Subscription {$subscription->id} (tenant {$subscription->tenant_id}): {$subscription->currency_code} {$mrr} from {$firstPaid->paid_at->toIso8601String()}");

                    if (! $this->option('dry-run')) {
                        SubscriptionMrrMovement::query()->create([
                            'tenant_id' => $subscription->tenant_id,
                            'subscription_id' => $subscription->id,
                            'plan_id' => $subscription->plan_id,
                            'type' => 'new',
                            'currency_code' => $subscription->currency_code,
                            'mode' => 'test',
                            'mrr_before' => '0.0000',
                            'mrr_after' => $mrr,
                            'mrr_delta' => $mrr,
                            'reason' => 'backfill_first_payment',
                            'occurred_at' => $firstPaid->paid_at,
                        ]);
                    }

                    $added++;
                }
            });

        $this->info(($this->option('dry-run') ? 'Would add ' : 'Added ').$added.' movement(s).');

        return self::SUCCESS;
    }
}
