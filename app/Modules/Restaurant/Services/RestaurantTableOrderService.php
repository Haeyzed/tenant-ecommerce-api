<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Services;

use App\Modules\Cart\Services\PricingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosSettingsService;
use App\Modules\Pos\Services\PosTerminalService;
use App\Modules\Restaurant\Models\RestaurantReservation;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Table orders (spec §65.4): a POS order (order_source = pos) that stays
 * pending while dishes are added round by round, reserving stock at the
 * floor's warehouse, and is paid once at the end with the POS tender
 * rules. Lines are priced at the location in the base currency plus their
 * modifiers, taxed at the location's address; promotions do not apply to
 * table orders in v1 (D-127).
 */
final readonly class RestaurantTableOrderService
{
    public function __construct(
        private OrderService $orders,
        private PricingService $pricing,
        private TaxService $tax,
        private CurrencyService $currencies,
        private TenantSettingsService $settings,
        private PosSettingsService $posSettings,
        private PosSaleService $sales,
        private PosTerminalService $terminals,
        private ModifierSelectionService $modifiers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines  product_id, variant_id?, quantity, modifier_option_ids?
     * @param  array{customer_id?: int|null, customer_name?: string|null, customer_phone?: string|null}  $options
     */
    public function openTableOrder(RestaurantTable $table, array $lines, array $options, User $by): Order
    {
        $this->assertTablesEnabled();
        $table->loadMissing('floor.warehouse');
        $customer = isset($options['customer_id']) ? (Customer::query()->find($options['customer_id']) ?? throw ApiException::unprocessable('customer_invalid', 'This customer does not exist.')) : null;
        $priced = $this->priceLines($table->floor->warehouse, $lines);

        return DB::connection('tenant')->transaction(function () use ($table, $priced, $customer, $options, $by): Order {
            /** @var RestaurantTable $locked */
            $locked = RestaurantTable::query()->lockForUpdate()->findOrFail($table->id);

            if ($this->getCurrentOrder($locked) !== null) {
                throw ApiException::conflict('table_occupied', 'This table already has an open order.');
            }

            $order = $this->orders->createOrder([
                'order_source' => 'pos',
                'status' => Order::PENDING,
                'customer' => $customer,
                'customer_name' => $options['customer_name'] ?? $customer?->name,
                'customer_phone' => $options['customer_phone'] ?? $customer?->phone,
                'currency_code' => $this->currencies->baseCurrency(),
                'exchange_rate' => '1',
                'prices_include_tax' => $priced['inclusive'],
                'lines' => $priced['lines'],
                'totals' => $priced['totals'],
                'created_by_user_id' => $by->id,
                'restaurant_table_id' => $locked->id,
            ]);

            $this->modifiers->snapshot($order->items->sortBy('id')->values()->all(), $priced['modifiers']);
            $locked->forceFill(['status' => RestaurantTable::OCCUPIED])->save();

            return $order;
        });
    }

    /**
     * One round (§65.4): the new lines reserve their stock and join the
     * kitchen queue.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function addItems(Order $order, array $lines): Order
    {
        $this->assertTablesEnabled();
        $table = $this->tableOf($order);
        $priced = $this->priceLines($table->floor->warehouse, $lines, false);

        return DB::connection('tenant')->transaction(function () use ($order, $priced): Order {
            // The order lock serialises rounds, so the new lines are the ones after $last.
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $last = (int) OrderItem::query()->where('order_id', $order->id)->max('id');
            $order = $this->orders->appendItems($order, $priced['lines']);

            $this->modifiers->snapshot($order->items->where('id', '>', $last)->sortBy('id')->values()->all(), $priced['modifiers']);

            return $order;
        });
    }

    /**
     * Payment at the end (§65.4): the POS tender rules (§51.2) at a register
     * of the floor's location, with its open session when cash sessions are
     * on. The payments are recorded, the reservations become deductions and
     * the order completes in one transaction.
     *
     * @param  array<string, mixed>  $data  register_id, payments[], pos_session_id?, quote_total?
     */
    public function settle(Order $order, array $data, User $by): Order
    {
        $validated = Validator::make($data, [
            'register_id' => ['required', 'integer'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::in(PosSaleService::TENDERS)],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'payments.*.reference' => ['required_if:payments.*.method,card_terminal', 'nullable', 'string', 'max:100'],
            'payments.*.gift_card_code' => ['required_if:payments.*.method,gift_card', 'nullable', 'string', 'max:32'],
            'pos_session_id' => ['sometimes', 'nullable', 'integer'],
            'quote_total' => ['sometimes', 'nullable', 'numeric'],
        ])->validate();

        $table = $this->tableOf($order);
        $settings = $this->posSettings->getSettings();
        $payments = array_values($validated['payments']);
        $register = PosRegister::query()->find((int) $validated['register_id']) ?? throw ApiException::unprocessable('register_invalid', 'This register does not exist.');

        if (! $register->is_active) {
            throw ApiException::unprocessable('register_inactive', 'This register is inactive.');
        }

        if ($register->warehouse_id !== $table->floor->warehouse_id) {
            throw ApiException::unprocessable('register_wrong_location', 'Use a register at this floor\'s location.');
        }

        foreach (array_column($payments, 'method') as $method) {
            if (! in_array($method, $settings->enabled_payment_methods, true)) {
                throw ApiException::unprocessable('payment_method_disabled', "The {$method} payment method is not enabled for POS.", ['method' => $method]);
            }
        }

        if (count(array_keys(array_column($payments, 'method'), 'cash', true)) > 1) {
            throw ApiException::unprocessable('payment_invalid', 'Enter the cash tendered as one payment.');
        }

        $this->assertOpen($order);

        if (! OrderItem::query()->where('order_id', $order->id)->exists()) {
            throw ApiException::unprocessable('order_empty', 'Nothing has been ordered at this table. Void it instead.');
        }

        $total = Money::normalize((string) $order->total);

        if (isset($validated['quote_total']) && Money::cmp(Money::normalize((string) $validated['quote_total']), $total) !== 0) {
            throw ApiException::conflict('totals_changed', 'The bill changed. Review the new total.', ['total' => $total]);
        }

        $session = $this->sales->resolveSession($register, $settings->cash_register_enabled, $validated['pos_session_id'] ?? null, null);
        $plan = $this->sales->planTenders($payments, $total, false);

        // Card payments are re-verified with the provider before the commit (§51.3 step 4).
        foreach ($plan as $tender) {
            if ($tender['method'] === 'card_terminal') {
                $this->terminals->assertLiveMode();
                $this->terminals->verifyCharge($register, (string) $tender['reference']);
            }
        }

        return DB::connection('tenant')->transaction(function () use ($order, $table, $register, $session, $plan, $total, $by): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertOpen($locked);

            // Another round landed between the checks and the lock.
            if (Money::cmp(Money::normalize((string) $locked->total), $total) !== 0) {
                throw ApiException::conflict('totals_changed', 'The bill changed. Review the new total.', ['total' => Money::normalize((string) $locked->total)]);
            }

            $locked->forceFill(['pos_session_id' => $session?->id, 'status' => Order::COMPLETED, 'completed_at' => now()])->save();

            foreach ($plan as $tender) {
                $this->sales->recordTender($locked, $register, $session, $tender, $by, null);
            }

            // Reservations become deductions; the sale commits exactly once (§32.5).
            $this->orders->confirmOrder($locked, false, false);
            $this->orders->recalculatePaymentStatus($locked);

            $this->release($table, RestaurantReservation::COMPLETED, $locked);
            $order->setRawAttributes($locked->fresh()?->getAttributes() ?? $locked->getAttributes(), true);

            return $order;
        });
    }

    /**
     * Before settlement only (§65.4): the order is cancelled, which
     * releases its reservations, and the table is free again.
     */
    public function voidTableOrder(Order $order, string $reason): Order
    {
        $table = $this->tableOf($order);

        return DB::connection('tenant')->transaction(function () use ($order, $table, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertOpen($locked);

            $this->orders->cancelOrder($locked, trim($reason) === '' ? 'Table order voided' : $reason);
            $this->release($table, RestaurantReservation::CANCELLED, $locked);
            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    public function getCurrentOrder(RestaurantTable $table): ?Order
    {
        return Order::query()->where('restaurant_table_id', $table->id)->where('status', Order::PENDING)->whereNull('cancelled_at')->latest('id')->first();
    }

    /**
     * The table order's table, else 404: other orders are not table orders.
     */
    public function tableOf(Order $order): RestaurantTable
    {
        if ($order->restaurant_table_id === null || $order->order_source !== 'pos') {
            throw new NotFoundHttpException('Not found.');
        }

        return RestaurantTable::query()->with('floor.warehouse')->findOrFail($order->restaurant_table_id);
    }

    /**
     * Table features follow pos_settings.table_management_enabled (§65).
     */
    public function assertTablesEnabled(): void
    {
        if (! $this->posSettings->getSettings()->table_management_enabled) {
            throw ApiException::unprocessable('table_management_disabled', 'Turn on table management in the POS settings first.');
        }
    }

    /**
     * Prices lines at the location in the base currency: the resolved unit
     * price plus the selected modifiers, then tax at the location's
     * address (none without one), as a POS sale is taxed (§51.3).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, modifiers: list<list<array{modifier_option_id: int, name: string, price_adjustment: string}>>, totals: array<string, string>, inclusive: bool}
     */
    public function priceLines(Warehouse $warehouse, array $lines, bool $allowEmpty = true): array
    {
        Validator::make(['lines' => $lines], [
            'lines' => [$allowEmpty ? 'present' : 'required', 'array', ...($allowEmpty ? [] : ['min:1']), 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:10000'],
            'lines.*.modifier_option_ids' => ['sometimes', 'array', 'max:30'],
            'lines.*.modifier_option_ids.*' => ['integer', 'distinct'],
        ])->validate();

        $currency = $this->currencies->baseCurrency();
        $inclusive = (bool) $this->settings->get('prices_include_tax', false);
        $products = Product::query()->with('unit')->whereKey(array_column($lines, 'product_id'))->get()->keyBy('id');
        $variants = ProductVariant::query()->whereKey(array_filter(array_column($lines, 'variant_id')))->get()->keyBy('id');
        $priced = [];
        $selections = [];

        foreach (array_values($lines) as $i => $line) {
            /** @var Product|null $product */
            $product = $products->get((int) $line['product_id']);
            /** @var ProductVariant|null $variant */
            $variant = isset($line['variant_id']) ? $variants->get((int) $line['variant_id']) : null;

            if ($product === null || (isset($line['variant_id']) && ($variant === null || $variant->product_id !== $product->id)) || ! CheckoutService::sellable($product, $variant)) {
                throw ApiException::unprocessable('item_unavailable', 'An item on this order cannot be sold right now.', ['line' => $i]);
            }

            $physical = $product->isPhysical();
            $quantity = Quantity::normalize((string) $line['quantity']);
            $price = $this->pricing->resolveUnitPrice($product, $variant, $physical ? $warehouse : null, $currency, '1');
            $selection = $this->modifiers->resolve($product, array_values($line['modifier_option_ids'] ?? []), $i);
            $unit = Money::add($price->unitPrice, $selection['adjustment']);

            if (Money::cmp($unit, '0') < 0) {
                throw ApiException::unprocessable('modifier_invalid', 'These options take the price below zero.', ['line' => $i]);
            }

            $selections[] = $selection['modifiers'];
            $priced[] = [
                'product' => $product,
                'variant' => $variant,
                'warehouse' => $physical ? $warehouse : null,
                'quantity' => $quantity,
                'unit_price' => $unit,
                'price_source' => $price->source,
                'subtotal' => Money::round(bcmul($unit, $quantity, 10), $currency),
                'kitchen_status' => 'pending',
            ];
        }

        $address = $warehouse->country_id === null ? null : ['country_id' => $warehouse->country_id, 'state_id' => $warehouse->state_id, 'address_id' => null];
        $taxed = $address === null || $priced === [] ? null : $this->tax->calculateForLines(array_map(static fn (array $l): array => [
            'amount' => $l['subtotal'], 'tax_class' => (string) ($l['product']->tax_class ?: 'standard'), 'origin' => $warehouse,
        ], $priced), $address, $warehouse, '0', $currency);
        $subtotal = $tax = Money::normalize(0);

        foreach ($priced as $i => $line) {
            $lineTax = $taxed['lines'][$i]['tax_amount'] ?? Money::normalize(0);
            $priced[$i] = [
                ...$line,
                'tax_rate_applied' => $taxed['lines'][$i]['tax_rate_applied'] ?? '0',
                'tax_amount' => $lineTax,
                'tax_breakdown' => $taxed['lines'][$i]['tax_breakdown'] ?? null,
                'line_total' => $inclusive ? $line['subtotal'] : Money::add($line['subtotal'], $lineTax),
            ];
            unset($priced[$i]['subtotal']);
            $subtotal = Money::add($subtotal, $line['subtotal']);
            $tax = Money::add($tax, $lineTax);
        }

        return [
            'lines' => $priced,
            'modifiers' => $selections,
            'totals' => ['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $inclusive ? $subtotal : Money::add($subtotal, $tax)],
            'inclusive' => $inclusive,
        ];
    }

    private function assertOpen(Order $order): void
    {
        if ($order->status !== Order::PENDING || $order->cancelled_at !== null || $order->confirmed_at !== null) {
            throw ApiException::unprocessable('table_order_closed', 'This table order is already settled or voided.');
        }

        if (OrderPayment::query()->where('order_id', $order->id)->where('status', OrderPayment::SUCCESSFUL)->exists()) {
            throw ApiException::unprocessable('order_has_payments', 'This order has payments already.');
        }
    }

    /**
     * The table is free again and the seated reservation, if any, closes.
     */
    private function release(RestaurantTable $table, string $reservationStatus, Order $order): void
    {
        RestaurantTable::query()->whereKey($table->id)->update(['status' => RestaurantTable::AVAILABLE, 'updated_at' => now()]);
        RestaurantReservation::query()->where('order_id', $order->id)->where('status', RestaurantReservation::SEATED)
            ->update(['status' => $reservationStatus, 'updated_at' => now()]);
    }
}
