<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductPricing;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Promotions\Models\Coupon;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\PromotionRedemption;
use App\Modules\Promotions\Models\PromotionTarget;
use App\Modules\Promotions\Support\PricingContext;
use App\Modules\Promotions\Support\PricingLine;
use App\Modules\Promotions\Support\PromotionCache;
use App\Modules\Promotions\Support\PromotionResult;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Support\MinorAllocator;
use App\Shared\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The discount engine (spec §37.6): the only code that decides which
 * promotions apply to a basket and how much each is worth. It reads and
 * never writes; the same input gives the same output. Usage checks here
 * are advisory: the authoritative ones run under lock at commit (§37.5).
 */
final readonly class PromotionEngine
{
    private const int SCALE = 10;

    public function __construct(
        private PromotionCache $cache,
        private TenantSettingsService $settings,
        private ProductPricing $pricing,
    ) {}

    public function evaluate(PricingContext $context): PromotionResult
    {
        $at = $context->at ?? now();
        $currency = $context->currency;
        $info = $this->lineInfo($context->lines);
        $groupId = $context->customerGroupId ?? $this->defaultGroupId();

        $subtotals = [];

        foreach ($context->lines as $line) {
            $subtotals[$line->position] = $line->subtotal($currency);
        }

        // 1. Candidates: running automatic promotions plus the coupon's.
        $candidates = [];

        foreach ($this->cache->automatic() as $promotion) {
            if ($this->inWindow($promotion, $at)) {
                $candidates[] = [$promotion, null];
            }
        }

        [$coupon, $couponReason] = $context->couponCode !== null ? $this->resolveCoupon($context, $at) : [null, null];

        if ($coupon !== null) {
            $candidates[] = [$coupon->promotion, $coupon];
        }

        // 2–5. Eligibility, eligible lines, thresholds and the ranking amount.
        $evaluated = [];

        foreach ($candidates as [$promotion, $candidateCoupon]) {
            $eligible = [];
            $reason = $this->buyerReason($promotion, $context, $groupId, $at);

            if ($reason === null) {
                $eligible = $this->eligibleLines($promotion, $context->lines, $info);
                $reason = $eligible === [] ? 'no_eligible_items' : $this->thresholdReason($promotion, $eligible, $subtotals, $context);
            }

            if ($reason !== null) {
                if ($candidateCoupon !== null) {
                    $couponReason = $reason;
                }

                continue;
            }

            $evaluated[] = [
                'promotion' => $promotion,
                'coupon' => $candidateCoupon,
                'eligible' => $eligible,
                'rank' => $this->rawAmount($promotion, $this->sum($eligible, $subtotals), $context),
            ];
        }

        usort($evaluated, static fn (array $a, array $b): int => ($b['promotion']->priority <=> $a['promotion']->priority)
            ?: Money::cmp($b['rank'], $a['rank'])
            ?: ($a['promotion']->id <=> $b['promotion']->id));

        // 6. Selection: one line promotion per line, one order and one
        // shipping promotion; an exclusive promotion stands alone.
        $zero = Money::normalize(0);
        $lineDiscounts = array_map(static fn (): string => $zero, $subtotals);
        $sellerFunded = $lineDiscounts;
        $claimed = [];
        $applied = [];
        $orderCandidate = null;
        $shippingCandidate = null;
        $anyApplied = false;
        $exclusiveApplied = false;

        foreach ($evaluated as $candidate) {
            /** @var Promotion $promotion */
            $promotion = $candidate['promotion'];
            $blocked = $exclusiveApplied || ($promotion->is_exclusive && $anyApplied);

            if (! $blocked && $promotion->scope === 'line') {
                $free = array_values(array_filter($candidate['eligible'], static fn (int $p): bool => ! isset($claimed[$p])));
                $reason = $free === [] ? 'not_combinable' : $this->thresholdReason($promotion, $free, $subtotals, $context);
                $amount = $reason === null ? $this->rawAmount($promotion, $this->sum($free, $subtotals), $context) : $zero;

                if ($reason !== null || ! Money::isPositive($amount)) {
                    if ($candidate['coupon'] !== null) {
                        $couponReason = $reason ?? 'no_eligible_items';
                    }

                    continue;
                }

                $shares = MinorAllocator::allocate($amount, array_intersect_key($subtotals, array_flip($free)), $currency);

                foreach ($shares as $position => $share) {
                    $lineDiscounts[$position] = Money::add($lineDiscounts[$position], $share);
                    $claimed[$position] = true;

                    if ($promotion->seller_id !== null) {
                        $sellerFunded[$position] = Money::add($sellerFunded[$position], $share);
                    }
                }

                $applied[] = $this->appliedRow($promotion, $candidate['coupon'], $amount);
            } elseif (! $blocked && $promotion->scope === 'order' && $orderCandidate === null) {
                $orderCandidate = $candidate;
            } elseif (! $blocked && $promotion->scope === 'shipping' && $shippingCandidate === null) {
                $shippingCandidate = $candidate;
            } else {
                if ($candidate['coupon'] !== null) {
                    $couponReason = 'not_combinable';
                }

                continue;
            }

            $anyApplied = true;
            $exclusiveApplied = $exclusiveApplied || $promotion->is_exclusive;
        }

        // The order promotion works on the amounts after line promotions.
        $orderDiscount = $zero;

        if ($orderCandidate !== null) {
            /** @var Promotion $promotion */
            $promotion = $orderCandidate['promotion'];
            $post = [];

            foreach ($orderCandidate['eligible'] as $position) {
                $post[$position] = Money::sub($subtotals[$position], $lineDiscounts[$position]);
            }

            $amount = $this->rawAmount($promotion, $this->sum(array_keys($post), $post), $context);

            if (Money::isPositive($amount)) {
                foreach (MinorAllocator::allocate($amount, $post, $currency) as $position => $share) {
                    $lineDiscounts[$position] = Money::add($lineDiscounts[$position], $share);

                    if ($promotion->seller_id !== null) {
                        $sellerFunded[$position] = Money::add($sellerFunded[$position], $share);
                    }
                }

                $orderDiscount = $amount;
                $applied[] = $this->appliedRow($promotion, $orderCandidate['coupon'], $amount);
            } elseif ($orderCandidate['coupon'] !== null) {
                $couponReason = 'no_eligible_items';
            }
        }

        $shippingDiscount = $zero;

        if ($shippingCandidate !== null) {
            $shippingDiscount = $this->rawAmount($shippingCandidate['promotion'], $zero, $context);
            $applied[] = $this->appliedRow($shippingCandidate['promotion'], $shippingCandidate['coupon'], $shippingDiscount);
        }

        // 8. Never negative.
        $lines = [];

        foreach ($subtotals as $position => $subtotal) {
            $lines[$position] = [
                'discount_amount' => Money::min($lineDiscounts[$position], $subtotal),
                'seller_funded_discount_amount' => Money::min($sellerFunded[$position], $subtotal),
            ];
        }

        $couponApplied = $coupon !== null && in_array($coupon->promotion_id, array_column($applied, 'promotion_id'), true);

        return new PromotionResult(
            $lines,
            $orderDiscount,
            $shippingDiscount,
            $applied,
            $coupon?->id,
            $coupon === null ? $couponReason : ($couponApplied ? null : ($couponReason ?? 'not_combinable')),
        );
    }

    /**
     * Storefront badges (§37.6): per product, the best running automatic
     * line promotion the buyer would get without any threshold condition.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array{promotion_id: int, label: string, discount_type: string, discount_value: string|null, price: string}>
     */
    public function previewForProducts(Collection $products, ?Customer $customer): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $at = now();
        $currency = (string) $this->settings->get('default_currency', 'USD');
        $context = new PricingContext([], $currency, $customer?->id, $customer?->customer_group_id, $customer?->email);
        $groupId = $customer?->customer_group_id ?? $this->defaultGroupId();

        $promotions = $this->cache->automatic()->filter(fn (Promotion $p): bool => $p->scope === 'line'
            && $this->inWindow($p, $at)
            && ! $p->first_order_only
            && $p->min_subtotal_amount === null && $p->max_subtotal_amount === null
            && $p->min_eligible_quantity === null && $p->max_eligible_quantity === null
            && $p->targets->where('target_type', 'warehouse')->isEmpty()
            && $this->buyerReason($p, $context, $groupId, $at) === null)
            ->sortBy([['priority', 'desc'], ['id', 'asc']])->values();

        if ($promotions->isEmpty()) {
            return [];
        }

        $lines = $products->values()->map(fn (Product $p, int $i): PricingLine => new PricingLine(
            $i, $p, null, '1', $this->pricing->effectivePrice($p),
            Money::cmp($this->pricing->effectivePrice($p), $this->pricing->catalogPrice($p)) < 0 ? 'flash_sale' : 'base',
        ))->all();
        $info = $this->lineInfo($lines);
        $previews = [];

        foreach ($lines as $line) {
            $best = null;

            foreach ($promotions as $promotion) {
                if ($this->eligibleLines($promotion, [$line], $info) === []) {
                    continue;
                }

                $amount = $this->rawAmount($promotion, $line->unitPrice, new PricingContext([], $currency));

                if (Money::isPositive($amount) && ($best === null || $promotion->priority > $best[0]->priority
                    || ($promotion->priority === $best[0]->priority && Money::cmp($amount, $best[1]) > 0))) {
                    $best = [$promotion, $amount];
                }
            }

            if ($best !== null) {
                $previews[$line->product->id] = [
                    'promotion_id' => $best[0]->id,
                    'label' => $best[0]->label(),
                    'discount_type' => $best[0]->discount_type,
                    'discount_value' => $best[0]->discount_value !== null ? (string) $best[0]->discount_value : null,
                    'price' => Money::sub($line->unitPrice, $best[1]),
                ];
            }
        }

        return $previews;
    }

    /**
     * @return array{0: Coupon|null, 1: string|null}
     */
    private function resolveCoupon(PricingContext $context, CarbonInterface $at): array
    {
        $coupon = Coupon::query()->with('promotion.targets')->where('code', Coupon::normalize((string) $context->couponCode))->first();

        if ($coupon === null) {
            return [null, 'not_found'];
        }

        $promotion = $coupon->promotion;
        $reason = match (true) {
            ! $coupon->is_active, $promotion === null, $promotion->trashed(), ! $promotion->is_active, $promotion->trigger !== Promotion::COUPON => 'inactive',
            $promotion->starts_at !== null && $promotion->starts_at->gt($at) => 'not_started',
            ($coupon->expires_at !== null && $coupon->expires_at->lte($at)) || ($promotion->ends_at !== null && $promotion->ends_at->lte($at)) => 'expired',
            $coupon->assigned_customer_id !== null && $coupon->assigned_customer_id !== $context->customerId => 'customer_not_eligible',
            $coupon->usage_limit !== null && $coupon->times_redeemed >= $coupon->usage_limit => 'usage_limit_reached',
            default => null,
        };

        return $reason === null ? [$coupon, null] : [null, $reason];
    }

    private function inWindow(Promotion $promotion, CarbonInterface $at): bool
    {
        return $promotion->is_active && ! $promotion->trashed()
            && ($promotion->starts_at === null || $promotion->starts_at->lte($at))
            && ($promotion->ends_at === null || $promotion->ends_at->gt($at));
    }

    /**
     * Days, time window, channel, buyer targets, first order and usage.
     */
    private function buyerReason(Promotion $promotion, PricingContext $context, ?int $groupId, CarbonInterface $at): ?string
    {
        if (! $this->validNow($promotion, $at)) {
            return 'not_valid_now';
        }

        if ($context->channel === PricingContext::POS ? ! $promotion->applies_to_pos : ! $promotion->applies_to_online) {
            return 'channel_not_allowed';
        }

        foreach (['customer' => $context->customerId, 'customer_group' => $groupId] as $type => $value) {
            $rows = $promotion->targets->where('target_type', $type);
            $includes = $rows->where('mode', 'include')->pluck('target_id')->all();
            $excludes = $rows->where('mode', 'exclude')->pluck('target_id')->all();

            if (($includes !== [] && ! in_array($value, $includes, true)) || ($value !== null && in_array($value, $excludes, true))) {
                return 'customer_not_eligible';
            }
        }

        if ($promotion->first_order_only && ! $context->isFirstOrder) {
            return 'first_order_only';
        }

        if ($promotion->usage_limit_total !== null && $promotion->times_redeemed >= $promotion->usage_limit_total) {
            return 'usage_limit_reached';
        }

        if ($promotion->usage_limit_per_customer !== null && ($context->customerId !== null || filled($context->email))) {
            $used = PromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->whereIn('status', PromotionRedemption::COUNTING)
                ->when($context->customerId !== null,
                    static fn ($q) => $q->where('customer_id', $context->customerId),
                    static fn ($q) => $q->where('customer_email', strtolower((string) $context->email)))
                ->count();

            if ($used >= $promotion->usage_limit_per_customer) {
                return 'customer_limit_reached';
            }
        }

        return null;
    }

    /**
     * Days and hours in the store's timezone. A window that wraps midnight
     * belongs to the day on which it started.
     */
    public function validNow(Promotion $promotion, CarbonInterface $at): bool
    {
        $local = $at->copy()->setTimezone((string) ($this->settings->get('timezone') ?: 'UTC'));
        $day = $local;

        if ($promotion->valid_time_from !== null && $promotion->valid_time_to !== null) {
            $time = $local->format('H:i:s');
            $from = substr($promotion->valid_time_from, 0, 8);
            $to = substr($promotion->valid_time_to, 0, 8);

            if ($from <= $to) {
                if ($time < $from || $time >= $to) {
                    return false;
                }
            } elseif ($time < $to) {
                $day = $local->copy()->subDay();
            } elseif ($time < $from) {
                return false;
            }
        }

        return $promotion->valid_days_of_week === null || ($promotion->valid_days_of_week & (1 << ($day->dayOfWeekIso - 1))) !== 0;
    }

    /**
     * Positions of the lines the promotion may discount (§37.3).
     *
     * @param  list<PricingLine>  $lines
     * @param  array<int, array<string, list<int>>>  $info
     * @return list<int>
     */
    private function eligibleLines(Promotion $promotion, array $lines, array $info): array
    {
        $targets = $promotion->targets->whereIn('target_type', PromotionTarget::LINE_TYPES);
        $includes = $targets->where('mode', 'include')->groupBy('target_type')->map(static fn ($rows) => $rows->pluck('target_id')->all())->all();
        $excludes = $targets->where('mode', 'exclude')->groupBy('target_type')->map(static fn ($rows) => $rows->pluck('target_id')->all())->all();

        $eligible = [];

        foreach ($lines as $line) {
            if (! $line->discountable || ($line->priceSource === 'flash_sale' && ! $promotion->applies_to_sale_items)) {
                continue;
            }

            // Warehouse targets apply only where the warehouse is known.
            if ((isset($includes['warehouse']) || isset($excludes['warehouse'])) && $line->warehouseId === null) {
                continue;
            }

            $keys = $info[$line->position];
            $matches = static fn (string $type, array $ids): bool => array_intersect($keys[$type] ?? [], $ids) !== [];

            foreach ($includes as $type => $ids) {
                if (! $matches($type, $ids)) {
                    continue 2;
                }
            }

            foreach ($excludes as $type => $ids) {
                if ($matches($type, $ids)) {
                    continue 2;
                }
            }

            $eligible[] = $line->position;
        }

        return $eligible;
    }

    /**
     * Subtotal and quantity bounds on the eligible lines, before any
     * discount, compared in the base currency.
     *
     * @param  list<int>  $positions
     * @param  array<int, string>  $subtotals
     */
    private function thresholdReason(Promotion $promotion, array $positions, array $subtotals, PricingContext $context): ?string
    {
        $base = bcdiv($this->sum($positions, $subtotals), $context->exchangeRate, self::SCALE);
        $quantity = '0';

        foreach ($context->lines as $line) {
            if (in_array($line->position, $positions, true)) {
                $quantity = bcadd($quantity, $line->quantity, 3);
            }
        }

        return match (true) {
            $promotion->min_subtotal_amount !== null && bccomp($base, (string) $promotion->min_subtotal_amount, self::SCALE) < 0,
            $promotion->min_eligible_quantity !== null && bccomp($quantity, (string) $promotion->min_eligible_quantity, 3) < 0 => 'minimum_not_met',
            $promotion->max_subtotal_amount !== null && bccomp($base, (string) $promotion->max_subtotal_amount, self::SCALE) > 0,
            $promotion->max_eligible_quantity !== null && bccomp($quantity, (string) $promotion->max_eligible_quantity, 3) > 0 => 'maximum_exceeded',
            default => null,
        };
    }

    /**
     * Percentage of the base, or a fixed amount capped at the base, or the
     * shipping amount; then capped at max_discount_amount and rounded.
     */
    private function rawAmount(Promotion $promotion, string $base, PricingContext $context): string
    {
        $rate = $context->exchangeRate;

        $amount = match ($promotion->discount_type) {
            Promotion::PERCENTAGE => bcdiv(bcmul($base, (string) $promotion->discount_value, self::SCALE), '100', self::SCALE),
            Promotion::FIXED => Money::min(bcmul((string) $promotion->discount_value, $rate, self::SCALE), $base),
            default => $context->shippingAmount ?? '0',
        };

        if ($promotion->max_discount_amount !== null) {
            $cap = bcmul((string) $promotion->max_discount_amount, $rate, self::SCALE);
            $amount = bccomp($amount, $cap, self::SCALE) > 0 ? $cap : $amount;
        }

        return Money::round($amount, $context->currency);
    }

    /**
     * @param  list<int>  $positions
     * @param  array<int, string>  $amounts
     */
    private function sum(array $positions, array $amounts): string
    {
        return array_reduce($positions, static fn (string $sum, int $p): string => Money::add($sum, $amounts[$p]), Money::normalize(0));
    }

    /**
     * @return array{promotion_id: int, coupon_id: int|null, scope: string, discount_type: string, label: string, amount: string, seller_id: int|null}
     */
    private function appliedRow(Promotion $promotion, ?Coupon $coupon, string $amount): array
    {
        return [
            'promotion_id' => $promotion->id,
            'coupon_id' => $coupon?->id,
            'scope' => $promotion->scope,
            'discount_type' => $promotion->discount_type,
            'label' => $promotion->label(),
            'amount' => $amount,
            'seller_id' => $promotion->seller_id,
        ];
    }

    /**
     * The matchable keys of each line: product, variant, categories with
     * their ancestors, brand, seller and warehouse.
     *
     * @param  list<PricingLine>  $lines
     * @return array<int, array<string, list<int>>>
     */
    private function lineInfo(array $lines): array
    {
        $productIds = array_values(array_unique(array_map(static fn (PricingLine $l): int => $l->product->id, $lines)));
        $categories = $productIds === [] ? collect() : DB::connection('tenant')->table('product_categories')->whereIn('product_id', $productIds)->get(['product_id', 'category_id'])->groupBy('product_id');
        $parents = $productIds === [] ? [] : Category::query()->pluck('parent_id', 'id')->all();

        $withAncestors = static function (array $ids) use ($parents): array {
            $all = [];

            foreach ($ids as $id) {
                for ($current = (int) $id, $guard = 0; $current !== 0 && $guard < 64; $current = (int) ($parents[$current] ?? 0), $guard++) {
                    $all[$current] = true;
                }
            }

            return array_keys($all);
        };

        $info = [];

        foreach ($lines as $line) {
            $info[$line->position] = [
                'product' => [$line->product->id],
                'product_variant' => $line->variant === null ? [] : [$line->variant->id],
                'category' => $withAncestors(($categories[$line->product->id] ?? collect())->pluck('category_id')->all()),
                'brand' => $line->product->brand_id === null ? [] : [$line->product->brand_id],
                'seller' => $line->product->seller_id === null ? [] : [$line->product->seller_id],
                'warehouse' => $line->warehouseId === null ? [] : [$line->warehouseId],
            ];
        }

        return $info;
    }

    private function defaultGroupId(): ?int
    {
        $id = CustomerGroup::query()->where('is_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }
}
