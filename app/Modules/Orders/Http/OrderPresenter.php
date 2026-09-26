<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Promotions\Models\PromotionRedemption;

/**
 * JSON shapes of orders. The customer view never shows costs, warehouses,
 * internal ids of staff or test flags beyond is_test.
 */
final class OrderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function order(Order $order, bool $detail, bool $admin, ?string $balance = null): array
    {
        $money = static fn (mixed $v): string => (string) $v;

        $base = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'currency_code' => $order->currency_code,
            'total' => $money($order->total),
            'placed_at' => $order->placed_at->toIso8601String(),
            'items_count' => $order->getAttributes()['items_count'] ?? ($order->relationLoaded('items') ? $order->items->count() : null),
            'is_test' => $order->is_test,
        ];

        if ($admin) {
            $base += [
                'order_source' => $order->order_source,
                'order_type' => $order->order_type,
                'customer' => ['id' => $order->customer_id, 'name' => $order->customer_name, 'email' => $order->customer_email],
            ];
        }

        if (! $detail) {
            return $base;
        }

        return [
            ...$base,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'prices_include_tax' => $order->prices_include_tax,
            'subtotal' => $money($order->subtotal),
            'discount_amount' => $money($order->discount_amount),
            'shipping_amount' => $money($order->shipping_amount),
            'shipping_discount_amount' => $money($order->shipping_discount_amount),
            'shipping_tax_amount' => $money($order->shipping_tax_amount),
            'tax_amount' => $money($order->tax_amount),
            'gift_card_amount_applied' => $money($order->gift_card_amount_applied),
            'reward_points_discount_amount' => $money($order->reward_points_discount_amount),
            'balance_due' => $balance,
            'shipping_method' => $order->relationLoaded('shippingMethod') && $order->shippingMethod !== null ? ['id' => $order->shippingMethod->id, 'name' => $order->shippingMethod->name] : null,
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
            'customer_note' => $order->customer_note,
            'payment_expires_at' => $order->payment_expires_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            'items' => $order->items->map(fn (OrderItem $item): array => $this->item($item, $admin))->values()->all(),
            'promotions' => $order->relationLoaded('redemptions') ? $order->redemptions->map(static fn (PromotionRedemption $r): array => [
                'label' => $r->public_label_snapshot ?? $r->promotion_name_snapshot,
                'coupon_code' => $r->coupon_code_snapshot,
                'amount' => (string) $r->discount_amount,
                ...($admin ? ['promotion_id' => $r->promotion_id, 'status' => $r->status] : []),
            ])->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(OrderItem $item, bool $admin): array
    {
        return [
            'id' => $item->id,
            'product' => $item->product === null ? null : ['id' => $item->product->id, 'slug' => $item->product->slug, 'product_type' => $item->product->product_type],
            'variant_id' => $item->product_variant_id,
            'name' => $item->name_snapshot,
            'sku' => $item->sku_snapshot,
            'quantity' => (string) $item->quantity,
            'quantity_shipped' => (string) $item->quantity_shipped,
            'unit_price' => (string) $item->unit_price,
            'discount_amount' => (string) $item->discount_amount,
            'tax_rate_applied' => (string) $item->tax_rate_applied,
            'tax_amount' => (string) $item->tax_amount,
            'line_total' => (string) $item->line_total,
            ...($admin ? [
                'price_source' => $item->price_source,
                'unit_cost_snapshot' => $item->unit_cost_snapshot === null ? null : (string) $item->unit_cost_snapshot,
                'tax_breakdown' => $item->tax_breakdown,
                'warehouse' => $item->warehouse_id === null ? null : ['id' => $item->warehouse_id, 'name' => $item->warehouse?->name],
            ] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(OrderPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'kind' => $payment->kind,
            'payment_method' => $payment->payment_method,
            'provider' => $payment->provider,
            'mode' => $payment->mode,
            'status' => $payment->status,
            'reference' => $payment->reference,
            'provider_reference' => $payment->provider_reference,
            'amount_paid' => (string) $payment->amount_paid,
            'amount_received' => $payment->amount_received === null ? null : (string) $payment->amount_received,
            'change_given' => $payment->change_given === null ? null : (string) $payment->change_given,
            'currency_code' => $payment->currency_code,
            'refund_of_order_payment_id' => $payment->refund_of_order_payment_id,
            'recorded_by' => $payment->recorded_by_user_id === null ? null : ['id' => $payment->recorded_by_user_id, 'name' => $payment->recorder?->name],
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'notes' => $payment->notes,
            'failure_reason' => $payment->meta['failure_reason'] ?? null,
            'created_at' => $payment->created_at->toIso8601String(),
        ];
    }
}
