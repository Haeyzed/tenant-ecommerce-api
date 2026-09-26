<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Promotions\Models\Coupon;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\PromotionRedemption;
use App\Modules\Promotions\Support\BuyerHistory;
use App\Modules\Promotions\Support\PromotionCache;
use App\Modules\Promotions\Support\PromotionResult;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The redemption lifecycle (spec §37.5): reserve at checkout commit, commit
 * on confirmation, release on expiry or cancellation before confirmation,
 * reverse on cancellation after it. Every method locks the promotion rows
 * in ascending id order, then the coupon rows, and adjusts times_redeemed
 * exactly once per redemption status change. Callers run it inside their
 * transaction.
 */
final readonly class PromotionRedemptionService
{
    public function __construct(
        private PromotionEngine $engine,
        private BuyerHistory $history,
        private PromotionCache $cache,
    ) {}

    /**
     * Re-checks every applied promotion under lock (409
     * promotion_unavailable) and records reserved redemptions.
     */
    public function reserve(Order $order, PromotionResult $result): void
    {
        if ($result->applied === []) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($order, $result): void {
            $promotions = $this->lockPromotions(array_column($result->applied, 'promotion_id'));
            $couponIds = array_values(array_filter(array_column($result->applied, 'coupon_id')));
            $coupons = $couponIds === [] ? collect() : Coupon::query()->whereKey($couponIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $email = $order->customer_email === null ? null : strtolower($order->customer_email);
            $channelPos = $order->order_source === 'pos';
            $now = now();

            foreach ($result->applied as $row) {
                /** @var Promotion|null $promotion */
                $promotion = $promotions->get($row['promotion_id']);
                /** @var Coupon|null $coupon */
                $coupon = $row['coupon_id'] === null ? null : $coupons->get($row['coupon_id']);

                $valid = $promotion !== null && ! $promotion->trashed() && $promotion->is_active
                    && ($promotion->starts_at === null || $promotion->starts_at->lte($now))
                    && ($promotion->ends_at === null || $promotion->ends_at->gt($now))
                    && $this->engine->validNow($promotion, $now)
                    && ($channelPos ? $promotion->applies_to_pos : $promotion->applies_to_online)
                    && ($promotion->usage_limit_total === null || $promotion->times_redeemed < $promotion->usage_limit_total)
                    && ($row['coupon_id'] === null || ($coupon !== null && $coupon->is_active
                        && ($coupon->usage_limit === null || $coupon->times_redeemed < $coupon->usage_limit)
                        && ($coupon->expires_at === null || $coupon->expires_at->gt($now))
                        && ($coupon->assigned_customer_id === null || $coupon->assigned_customer_id === $order->customer_id)))
                    && ($promotion->usage_limit_per_customer === null || $this->usedBy($promotion, $order->customer_id, $email) < $promotion->usage_limit_per_customer)
                    && (! $promotion->first_order_only || $this->history->isFirstOrder($order->customer_id, $email, $order->id));

                if (! $valid) {
                    throw ApiException::conflict('promotion_unavailable', 'A promotion on this basket is no longer available. Review the cart.', ['promotion_id' => $row['promotion_id']]);
                }

                /** @var Promotion $promotion */
                $promotion->increment('times_redeemed');
                $coupon?->increment('times_redeemed');

                $redemption = new PromotionRedemption;
                $redemption->forceFill([
                    'promotion_id' => $promotion->id,
                    'coupon_id' => $coupon?->id,
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'customer_email' => $email,
                    'promotion_name_snapshot' => $promotion->name,
                    'public_label_snapshot' => $promotion->public_label,
                    'coupon_code_snapshot' => $coupon?->code,
                    'scope_snapshot' => $promotion->scope,
                    'discount_type_snapshot' => $promotion->discount_type,
                    'discount_value_snapshot' => $promotion->discount_value,
                    'discount_amount' => $row['amount'],
                    // Baskets are in the base currency until multi-currency (§48).
                    'base_discount_amount' => Money::normalize($row['amount']),
                    'seller_id' => $promotion->seller_id,
                    'status' => PromotionRedemption::RESERVED,
                ])->save();
            }
        });

        $this->cache->flush();
    }

    public function commit(Order $order): void
    {
        $this->transition($order, [PromotionRedemption::RESERVED], PromotionRedemption::COMMITTED, false);
    }

    public function release(Order $order): void
    {
        $this->transition($order, [PromotionRedemption::RESERVED], PromotionRedemption::RELEASED, true);
    }

    public function reverse(Order $order): void
    {
        $this->transition($order, [PromotionRedemption::COMMITTED], PromotionRedemption::REVERSED, true);
    }

    /**
     * Operational repair (§77.6): times_redeemed = reserved + committed.
     */
    public function recount(Promotion $promotion): void
    {
        DB::connection('tenant')->transaction(static function () use ($promotion): void {
            $locked = Promotion::withTrashed()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            $counting = PromotionRedemption::query()->where('promotion_id', $locked->id)->whereIn('status', PromotionRedemption::COUNTING);
            $locked->forceFill(['times_redeemed' => (clone $counting)->count()])->saveQuietly();

            foreach (Coupon::query()->where('promotion_id', $locked->id)->orderBy('id')->lockForUpdate()->get() as $coupon) {
                $coupon->forceFill(['times_redeemed' => (clone $counting)->where('coupon_id', $coupon->id)->count()])->saveQuietly();
            }
        });

        $this->cache->flush();
    }

    /**
     * Idempotent by status: only rows in $from move, and each move adjusts
     * the counters once.
     *
     * @param  list<string>  $from
     */
    private function transition(Order $order, array $from, string $to, bool $decrement): void
    {
        DB::connection('tenant')->transaction(function () use ($order, $from, $to, $decrement): void {
            $rows = PromotionRedemption::query()->where('order_id', $order->id)->whereIn('status', $from)->orderBy('id')->get();

            if ($rows->isEmpty()) {
                return;
            }

            $promotions = $this->lockPromotions($rows->pluck('promotion_id')->all());
            $coupons = Coupon::query()->whereKey($rows->pluck('coupon_id')->filter()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($rows as $row) {
                $locked = PromotionRedemption::query()->whereKey($row->id)->lockForUpdate()->first();

                if ($locked === null || ! in_array($locked->status, $from, true)) {
                    continue;
                }

                $locked->forceFill([
                    'status' => $to,
                    'committed_at' => $to === PromotionRedemption::COMMITTED ? now() : $locked->committed_at,
                    'released_at' => $decrement ? now() : $locked->released_at,
                ])->save();

                if ($decrement) {
                    $promotions->get($locked->promotion_id)?->decrement('times_redeemed');

                    if ($locked->coupon_id !== null) {
                        $coupons->get($locked->coupon_id)?->decrement('times_redeemed');
                    }
                }
            }
        });

        if ($decrement) {
            $this->cache->flush();
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Promotion>
     */
    private function lockPromotions(array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return Promotion::withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function usedBy(Promotion $promotion, ?int $customerId, ?string $email): int
    {
        if ($customerId === null && $email === null) {
            return 0;
        }

        return PromotionRedemption::query()
            ->where('promotion_id', $promotion->id)
            ->whereIn('status', PromotionRedemption::COUNTING)
            ->when($customerId !== null, static fn ($q) => $q->where('customer_id', $customerId), static fn ($q) => $q->where('customer_email', $email))
            ->count();
    }
}
