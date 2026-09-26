<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http;

use App\Modules\Promotions\Models\Coupon;
use App\Modules\Promotions\Models\FlashSale;
use App\Modules\Promotions\Models\FlashSaleProduct;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\PromotionRedemption;
use App\Modules\Promotions\Models\PromotionTarget;

/**
 * JSON shapes of promotion records. Days of week are ISO day numbers; the
 * bitmask is never exposed (§37.2).
 */
final class PromotionPresenter
{
    /**
     * @param  array<string, mixed>|null  $stats
     * @return array<string, mixed>
     */
    public function promotion(Promotion $promotion, bool $detail, ?array $stats = null): array
    {
        $money = static fn (mixed $v): ?string => $v === null ? null : (string) $v;

        $base = [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'public_label' => $promotion->public_label,
            'trigger' => $promotion->trigger,
            'scope' => $promotion->scope,
            'discount_type' => $promotion->discount_type,
            'discount_value' => $money($promotion->discount_value),
            'starts_at' => $promotion->starts_at?->toIso8601String(),
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'is_active' => (bool) $promotion->is_active,
            'is_exclusive' => (bool) $promotion->is_exclusive,
            'priority' => (int) $promotion->priority,
            'times_redeemed' => (int) $promotion->times_redeemed,
            'usage_limit_total' => $promotion->usage_limit_total,
            'seller_id' => $promotion->seller_id,
            'coupons_count' => $promotion->getAttributes()['coupons_count'] ?? null,
        ];

        if (! $detail) {
            return $base;
        }

        return [
            ...$base,
            'description' => $promotion->description,
            'max_discount_amount' => $money($promotion->max_discount_amount),
            'min_subtotal_amount' => $money($promotion->min_subtotal_amount),
            'max_subtotal_amount' => $money($promotion->max_subtotal_amount),
            'min_eligible_quantity' => $money($promotion->min_eligible_quantity),
            'max_eligible_quantity' => $money($promotion->max_eligible_quantity),
            'valid_days' => $promotion->valid_days,
            'valid_time_from' => $promotion->valid_time_from === null ? null : substr($promotion->valid_time_from, 0, 5),
            'valid_time_to' => $promotion->valid_time_to === null ? null : substr($promotion->valid_time_to, 0, 5),
            'applies_to_online' => (bool) $promotion->applies_to_online,
            'applies_to_pos' => (bool) $promotion->applies_to_pos,
            'applies_to_sale_items' => (bool) $promotion->applies_to_sale_items,
            'first_order_only' => (bool) $promotion->first_order_only,
            'usage_limit_per_customer' => $promotion->usage_limit_per_customer,
            'targets' => $promotion->targets->map(static fn (PromotionTarget $t): array => $t->only(['target_type', 'target_id', 'mode']))->values()->all(),
            'usage' => $stats,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function coupon(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'promotion_id' => $coupon->promotion_id,
            'code' => $coupon->code,
            'usage_limit' => $coupon->usage_limit,
            'times_redeemed' => (int) $coupon->times_redeemed,
            'assigned_customer_id' => $coupon->assigned_customer_id,
            'expires_at' => $coupon->expires_at?->toIso8601String(),
            'is_active' => (bool) $coupon->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function redemption(PromotionRedemption $redemption): array
    {
        return [
            'id' => $redemption->id,
            'order_id' => $redemption->order_id,
            'customer_id' => $redemption->customer_id,
            'coupon_code' => $redemption->coupon_code_snapshot,
            'discount_amount' => (string) $redemption->discount_amount,
            'base_discount_amount' => (string) $redemption->base_discount_amount,
            'status' => $redemption->status,
            'over_limit' => (bool) $redemption->over_limit,
            'created_at' => $redemption->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function flashSale(FlashSale $sale): array
    {
        return [
            'id' => $sale->id,
            'name' => $sale->name,
            'starts_at' => $sale->starts_at->toIso8601String(),
            'ends_at' => $sale->ends_at->toIso8601String(),
            'is_active' => (bool) $sale->is_active,
            'is_running' => $sale->isRunning(),
            'products' => $sale->products->map(fn (FlashSaleProduct $p): array => $this->flashSaleProduct($p))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function flashSaleProduct(FlashSaleProduct $row): array
    {
        return [
            'product' => ['id' => $row->product_id, 'name' => $row->product?->name, 'sku' => $row->product?->sku, 'price' => $row->product === null ? null : (string) $row->product->price],
            'sale_price' => (string) $row->sale_price,
            'quantity_limit' => $row->quantity_limit === null ? null : (string) $row->quantity_limit,
            'quantity_claimed' => (string) $row->quantity_claimed,
        ];
    }
}
