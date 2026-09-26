<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use App\Modules\Cart\Services\PricingService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Exchange resolution (spec §41.5): a replacement order at today's prices,
 * paid with exchange credit up to the returned value. A dearer replacement
 * leaves the difference due; a cheaper one refunds the difference on the
 * original order (after the transaction: provider calls never run inside).
 */
final readonly class ExchangeService
{
    public function __construct(
        private ReturnService $returns,
        private OrderService $orders,
        private OrderPaymentService $payments,
        private PricingService $pricing,
        private InventoryService $inventory,
        private TaxService $tax,
        private TenantSettingsService $settings,
    ) {}

    public function processExchange(OrderReturn $return, ?User $by = null): Order
    {
        [$replacement, $difference] = DB::connection('tenant')->transaction(function () use ($return, $by): array {
            $locked = $this->returns->lock($return, [OrderReturn::APPROVED, OrderReturn::RECEIVED], OrderReturn::EXCHANGED);

            if ($locked->resolution_type !== 'exchange') {
                throw ApiException::unprocessable('return_not_exchange', 'This return asks for a refund, not an exchange.');
            }

            $original = $locked->order;
            $currency = $original->currency_code;
            $inclusive = (bool) $this->settings->get('prices_include_tax', false);
            $address = $original->shipping_address === null ? null : ['country_id' => $original->shipping_address['country_id'] ?? null, 'state_id' => $original->shipping_address['state_id'] ?? null];
            $lines = [];

            foreach ($locked->items()->with(['exchangeProduct', 'exchangeVariant'])->get() as $item) {
                $product = $item->exchangeProduct;
                $warehouse = $product->isPhysical() ? $this->inventory->selectFulfillmentWarehouse($product, $item->exchangeVariant, (string) $item->quantity) : null;

                if ($product->isPhysical() && $warehouse === null) {
                    throw ApiException::conflict('stock_conflict', 'A replacement item is no longer in stock.', ['product_id' => $product->id]);
                }

                $price = $this->pricing->resolveUnitPrice($product, $item->exchangeVariant, $warehouse, $currency);
                $subtotal = Money::round(bcmul($price->unitPrice, (string) $item->quantity, 10), $currency);
                $lines[] = ['product' => $product, 'variant' => $item->exchangeVariant, 'warehouse' => $warehouse, 'quantity' => (string) $item->quantity,
                    'unit_price' => $price->unitPrice, 'price_source' => $price->source, 'subtotal' => $subtotal];
            }

            $taxed = $address === null ? null : $this->tax->calculateForLines(
                array_map(static fn (array $l): array => ['amount' => $l['subtotal'], 'tax_class' => (string) ($l['product']->tax_class ?: 'standard'), 'origin' => $l['warehouse']], $lines),
                $address, null, '0', $currency);

            $subtotal = $taxTotal = Money::normalize(0);

            foreach ($lines as $i => $line) {
                $lineTax = $taxed['lines'][$i]['tax_amount'] ?? Money::normalize(0);
                $lines[$i] += ['tax_rate_applied' => $taxed['lines'][$i]['tax_rate_applied'] ?? '0', 'tax_amount' => $lineTax,
                    'tax_breakdown' => $taxed['lines'][$i]['tax_breakdown'] ?? null, 'line_total' => $inclusive ? $line['subtotal'] : Money::add($line['subtotal'], $lineTax)];
                $subtotal = Money::add($subtotal, $line['subtotal']);
                $taxTotal = Money::add($taxTotal, $lineTax);
            }

            $total = $inclusive ? $subtotal : Money::add($subtotal, $taxTotal);

            $replacement = $this->orders->createOrder([
                'order_source' => 'admin',
                'order_type' => 'exchange_replacement',
                'status' => Order::PENDING,
                'is_test' => $original->is_test,
                'customer' => $original->customer,
                'customer_name' => $original->customer_name,
                'customer_email' => $original->customer_email,
                'customer_phone' => $original->customer_phone,
                'guest_token' => $original->guest_token,
                'currency_code' => $currency,
                'prices_include_tax' => $inclusive,
                'lines' => $lines,
                'totals' => ['subtotal' => $subtotal, 'tax_amount' => $taxTotal, 'total' => $total],
                'shipping_address' => $original->shipping_address,
                'billing_address' => $original->billing_address,
                'created_by_user_id' => $by?->id,
                'replaces_order_return_id' => $locked->id,
            ]);

            $value = $this->returns->returnedValue($locked);
            $credit = Money::min($value, $total);

            if (Money::isPositive($credit)) {
                $payment = new OrderPayment;
                $payment->forceFill([
                    'order_id' => $replacement->id,
                    'kind' => OrderPayment::PAYMENT,
                    'payment_method' => 'exchange_credit',
                    'mode' => OrderPaymentService::modeOf($replacement),
                    'status' => OrderPayment::SUCCESSFUL,
                    'reference' => 'EXC-'.Str::upper(Str::random(16)),
                    'amount_paid' => $credit,
                    'currency_code' => $currency,
                    'order_return_id' => $locked->id,
                    'recorded_by_user_id' => $by?->id,
                    'paid_at' => now(),
                    'notes' => 'Exchange credit from return '.$locked->return_number,
                ])->save();
            }

            $this->orders->recalculatePaymentStatus($replacement);
            $this->returns->restock($locked);
            $locked->forceFill(['status' => OrderReturn::EXCHANGED, 'resolved_at' => now()])->save();
            $return->setRawAttributes($locked->getAttributes(), true);

            return [$replacement, Money::sub($value, $credit)];
        });

        // A cheaper replacement: the difference goes back on the original order.
        if (Money::isPositive($difference)) {
            $this->payments->refundOrder($return->order, $difference, 'Exchange difference for return '.$return->return_number, $by, 'exchange-'.$return->id);
        }

        return $replacement;
    }

    public function getReplacementOrderForReturn(OrderReturn $return): ?Order
    {
        return Order::query()->where('replaces_order_return_id', $return->id)->first();
    }
}
