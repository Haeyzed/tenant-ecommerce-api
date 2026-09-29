<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Payments\Support\PaymentPostings;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports an order from WooCommerce or a social channel (spec §68.3,
 * §69.2) through OrderService::createOrder(): lines reserve stock at the
 * fulfilling warehouse, the idempotency key makes a rerun return the
 * same order, and an order already paid there is recorded as one "other"
 * payment and confirmed by the normal payment-status rules (A-60). The
 * totals must add up to the platform's total, so an order with fees or
 * adjustments we cannot represent is refused rather than imported wrong.
 */
final readonly class ExternalOrderImporter
{
    public function __construct(
        private OrderService $orders,
        private CurrencyService $currencies,
        private PaymentPostings $postings,
    ) {}

    public function import(ExternalOrder $external, Warehouse $warehouse): Order
    {
        if (($existing = Order::query()->where('idempotency_key', $external->idempotencyKey)->first()) !== null) {
            $this->syncPayment($existing, $external);

            return $existing;
        }

        $currency = strtoupper($external->currency);

        if ($currency !== $this->currencies->baseCurrency()) {
            throw ApiException::unprocessable('currency_unsupported', "Orders in {$currency} cannot be imported; the store sells in ".$this->currencies->baseCurrency().'.');
        }

        if ($external->lines === []) {
            throw ApiException::unprocessable('order_empty', 'The order has no product lines.');
        }

        $lines = [];
        $subtotal = $discount = $tax = Money::normalize(0);

        foreach ($external->lines as $line) {
            $lineSubtotal = Money::round(bcmul($line['unit_price'], $line['quantity'], 10), $currency);
            $net = Money::sub($lineSubtotal, $line['discount_amount']);
            $lines[] = [
                'product' => $line['product'], 'variant' => $line['variant'], 'warehouse' => $line['product']->isPhysical() ? $warehouse : null,
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'price_source' => $external->source,
                'discount_amount' => $line['discount_amount'], 'tax_amount' => $line['tax_amount'],
                'tax_rate_applied' => Money::isPositive($net) ? bcmul(bcdiv($line['tax_amount'], $net, 8), '100', 4) : '0',
                'line_total' => Money::add($net, $line['tax_amount']),
            ];
            $subtotal = Money::add($subtotal, $lineSubtotal);
            $discount = Money::add($discount, $line['discount_amount']);
            $tax = Money::add($tax, $line['tax_amount']);
        }

        $total = Money::add(Money::add(Money::sub($subtotal, $discount), $tax), Money::add($external->shippingAmount, $external->shippingTax));

        if (Money::cmp($total, Money::normalize($external->total)) !== 0) {
            throw ApiException::unprocessable('order_total_mismatch', 'The order total includes fees or adjustments that cannot be imported.', ['expected' => $external->total, 'calculated' => $total]);
        }

        $email = $external->customerEmail === null ? null : strtolower(trim($external->customerEmail));
        $customer = $email === null || $email === '' ? null : Customer::query()->where('email', $email)->first();

        $order = $this->orders->createOrder([
            'order_source' => $external->source,
            'status' => Order::PENDING,
            'customer' => $customer,
            'customer_name' => $external->customerName ?? $customer?->name,
            'customer_email' => $email ?: null,
            'customer_phone' => $external->customerPhone ?? $customer?->phone,
            'currency_code' => $currency,
            'exchange_rate' => '1',
            'prices_include_tax' => false,
            'lines' => $lines,
            'totals' => [
                'subtotal' => $subtotal, 'discount_amount' => $discount, 'shipping_amount' => $external->shippingAmount,
                'shipping_tax_amount' => $external->shippingTax, 'tax_amount' => Money::add($tax, $external->shippingTax), 'total' => $total,
            ],
            'shipping_address' => $external->shippingAddress,
            'idempotency_key' => $external->idempotencyKey,
            'placed_at' => $external->placedAt ?? now(),
        ]);

        $this->syncPayment($order, $external);

        return $order;
    }

    /**
     * Records the payment once the other platform reports the order paid;
     * the idempotency key makes this safe to repeat.
     */
    public function syncPayment(Order $order, ExternalOrder $external): void
    {
        if (! $external->paid || $order->status === Order::CANCELLED) {
            return;
        }

        $recorded = DB::connection('tenant')->transaction(function () use ($order, $external): bool {
            Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (OrderPayment::query()->where('order_id', $order->id)->where('kind', OrderPayment::PAYMENT)->where('idempotency_key', 'external-payment')->exists()) {
                return false;
            }

            $row = new OrderPayment;
            $row->forceFill([
                'order_id' => $order->id,
                'kind' => OrderPayment::PAYMENT,
                'payment_method' => 'other',
                'mode' => OrderPaymentService::modeOf($order),
                'status' => OrderPayment::SUCCESSFUL,
                'reference' => 'EXT-'.Str::upper(Str::random(16)),
                'idempotency_key' => 'external-payment',
                'amount_due' => (string) $order->total,
                'amount_paid' => (string) $order->total,
                'currency_code' => $order->currency_code,
                'paid_at' => now(),
                'notes' => $external->paidNote,
            ])->save();

            $this->postings->payment($row);

            return true;
        });

        if ($recorded) {
            $this->orders->recalculatePaymentStatus($order);
        }
    }

    /**
     * The other platform cancelled an order we have not confirmed yet.
     */
    public function cancelIfOpen(Order $order, string $reason): void
    {
        if ($order->confirmed_at === null && in_array($order->status, [Order::PENDING, Order::PROCESSING], true)) {
            $this->orders->cancelOrder($order, $reason);
        }
    }
}
