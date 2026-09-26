<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Enums\OverrideEffect;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Plans\Models\TenantFeature;
use App\Modules\Plans\Models\TenantLimitOverride;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Shared\Support\Money;

/**
 * The itemised amount of one billing cycle (spec §14.5). The platform's
 * computed total is always what is charged.
 */
final readonly class SubscriptionBillingService
{
    public function __construct(
        private PlatformCouponService $coupons,
        private ModuleRegistry $registry,
    ) {}

    /**
     * @param  PlanPrice|null  $price  the price the cycle is charged at (default: the subscription's)
     * @return array{lines: list<array{type: string, key: string, label: string, amount: string}>, total: string, currency_code: string}
     */
    public function calculateCycleTotal(Subscription $subscription, ?PlanPrice $price = null, bool $applyCoupon = true): array
    {
        $price ??= $subscription->planPrice;
        $price->loadMissing('plan');
        $currency = $subscription->currency_code;
        $planAmount = Money::normalize((string) $price->amount);

        $lines = [[
            'type' => 'plan',
            'key' => $price->plan->slug,
            'label' => $price->plan->name.' ('.$price->billing_interval.')',
            'amount' => $planAmount,
        ]];

        foreach ($this->billedModuleAddons($subscription) as $grant) {
            $lines[] = [
                'type' => 'module_addon',
                'key' => $grant->feature_key,
                'label' => $this->registry->has($grant->feature_key) ? $this->registry->get($grant->feature_key)->name : $grant->feature_key,
                'amount' => Money::normalize((string) $grant->extra_amount),
            ];
        }

        foreach ($this->billedLimitOverrides($subscription) as $override) {
            $lines[] = [
                'type' => 'limit_override',
                'key' => $override->limit_key,
                'label' => (string) config("limits.{$override->limit_key}.label", $override->limit_key),
                'amount' => Money::normalize((string) $override->extra_amount),
            ];
        }

        $redemption = $applyCoupon ? $this->coupons->openRedemption($subscription) : null;

        if ($redemption !== null && $redemption->cycles_applied < $redemption->cycles_total) {
            $discount = $this->coupons->discountFor($redemption, $planAmount, $currency);

            if (Money::isPositive($discount)) {
                $lines[] = [
                    'type' => 'coupon_discount',
                    'key' => $redemption->code_snapshot,
                    'label' => 'Coupon '.$redemption->code_snapshot,
                    'amount' => Money::sub('0', $discount),
                ];
            }
        }

        $total = array_reduce($lines, static fn (string $sum, array $line): string => Money::add($sum, $line['amount']), '0');

        return [
            'lines' => $lines,
            'total' => Money::round(Money::max($total, '0'), $currency),
            'currency_code' => $currency,
        ];
    }

    /**
     * The normalised monthly amount of the recurring cycle (§14.10).
     */
    public function monthlyRecurringAmount(Subscription $subscription): string
    {
        $total = $this->calculateCycleTotal($subscription)['total'];

        return $subscription->billing_interval === 'yearly' ? Money::div($total, '12') : $total;
    }

    /**
     * @return list<TenantFeature>
     */
    private function billedModuleAddons(Subscription $subscription): array
    {
        return TenantFeature::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('effect', OverrideEffect::Grant->value)
            ->where('billed', true)
            ->whereNotNull('extra_amount')
            ->where('extra_currency_code', $subscription->currency_code)
            ->inForce()
            ->orderBy('feature_key')
            ->get()
            ->all();
    }

    /**
     * @return list<TenantLimitOverride>
     */
    private function billedLimitOverrides(Subscription $subscription): array
    {
        return TenantLimitOverride::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('billed', true)
            ->where('is_active', true)
            ->whereNotNull('extra_amount')
            ->where('extra_currency_code', $subscription->currency_code)
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('limit_key')
            ->get()
            ->all();
    }
}
