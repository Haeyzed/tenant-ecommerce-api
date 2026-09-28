<?php

declare(strict_types=1);

namespace App\Modules\Pos\Services;

use App\Modules\Cart\Services\PricingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Checkout\Support\Quote;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Documents\Http\DocumentPresenter;
use App\Modules\Documents\Services\ReceiptPrinterService;
use App\Modules\GiftCards\Models\GiftCard;
use App\Modules\GiftCards\Services\GiftCardService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Payments\Support\PaymentPostings;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminalCharge;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Modules\Promotions\Services\PromotionEngine;
use App\Modules\Promotions\Services\PromotionRedemptionService;
use App\Modules\Promotions\Support\BuyerHistory;
use App\Modules\Promotions\Support\PricingContext;
use App\Modules\Promotions\Support\PricingLine;
use App\Modules\RewardPoints\Services\RewardPointService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The POS sale (spec §51.3): a one-pass order at the register's warehouse,
 * priced like checkout (catalogue price, POS promotions, tax at the
 * warehouse's location) and paid by one or more tenders in the same
 * payments ledger. Nothing touches stock unless the whole sale commits.
 */
final readonly class PosSaleService
{
    /** Tenders that are payment rows; credit_sale and reward_points are not (§51.2). */
    public const array TENDERS = ['cash', 'card_terminal', 'bank_transfer', 'cheque', 'gift_card'];

    public function __construct(
        private PricingService $pricing,
        private InventoryService $inventory,
        private PromotionEngine $promotions,
        private TaxService $tax,
        private TenantSettingsService $settings,
        private BuyerHistory $history,
        private OrderService $orders,
        private FlashSaleService $flashSales,
        private PromotionRedemptionService $redemptions,
        private CurrencyService $currencies,
        private GiftCardService $giftCards,
        private RewardPointService $rewardPoints,
        private PosSettingsService $posSettings,
        private PosSessionService $sessions,
        private PosTerminalService $terminals,
        private OrderPaymentService $payments,
        private PaymentPostings $postings,
        private NotificationDispatchService $notifications,
        private ReceiptPrinterService $printers,
    ) {}

    /**
     * Prices, promotions, tax and total, with no writes (§51.3 step 2).
     *
     * @param  list<array<string, mixed>>  $lines  product_id, variant_id?, quantity
     */
    public function quote(PosRegister $register, array $lines, ?Customer $customer = null, ?string $couponCode = null, int $rewardPoints = 0, ?CarbonInterface $at = null): Quote
    {
        Validator::make(['lines' => $lines], [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:100000'],
        ])->validate();

        $register->loadMissing('warehouse');
        $warehouse = $register->warehouse;
        $currency = $this->currencies->baseCurrency();
        $products = Product::withTrashed()->with('unit')->whereKey(array_column($lines, 'product_id'))->get()->keyBy('id');
        $variants = ProductVariant::withTrashed()->whereKey(array_filter(array_column($lines, 'variant_id')))->get()->keyBy('id');
        $issues = [];
        $priced = [];
        $wanted = [];

        foreach ($lines as $i => $line) {
            /** @var Product|null $product */
            $product = $products->get((int) $line['product_id']);
            /** @var ProductVariant|null $variant */
            $variant = isset($line['variant_id']) ? $variants->get((int) $line['variant_id']) : null;

            if ($product === null || (isset($line['variant_id']) && $variant === null)) {
                throw ApiException::unprocessable('item_unavailable', 'A product on this sale does not exist.', ['line' => $i]);
            }

            $quantity = Quantity::normalize((string) $line['quantity']);
            $status = CheckoutService::sellable($product, $variant) ? Quote::OK : Quote::UNAVAILABLE;
            $physical = $product->isPhysical();

            if ($status === Quote::OK && $physical) {
                $key = $product->id.':'.($variant?->id ?? 0);
                $wanted[$key] = Quantity::add($wanted[$key] ?? '0', $quantity);
                $available = $this->inventory->getAvailableStock($product, $variant, $warehouse) ?? '0';
                $status = Quantity::cmp($wanted[$key], $available) > 0 ? Quote::OUT_OF_STOCK : Quote::OK;
            }

            $price = $status === Quote::UNAVAILABLE ? null : $this->pricing->resolveUnitPrice($product, $variant, $physical ? $warehouse : null, $currency, '1');
            $zero = Money::normalize(0);

            $priced[] = [
                'item_id' => null,
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'status' => $status,
                'warehouse' => $physical ? $warehouse : null,
                'price' => $price,
                'line_subtotal' => $price === null ? $zero : Money::round(bcmul($price->unitPrice, $quantity, 10), $currency),
                'discount_amount' => $zero,
                'seller_funded_discount_amount' => $zero,
                'tax_rate_applied' => null,
                'tax_amount' => null,
                'tax_breakdown' => null,
                'line_total' => null,
            ];
        }

        $sellable = array_filter($priced, static fn (array $l): bool => $l['status'] === Quote::OK);
        $email = $customer?->email;

        // POS promotions, at the sale's own time for an offline sale (§51.6).
        $result = $this->promotions->evaluate(new PricingContext(
            lines: array_values(array_map(static fn (array $l, int $position): PricingLine => new PricingLine(
                $position, $l['product'], $l['variant'], $l['quantity'], $l['price']->unitPrice, $l['price']->source, $l['warehouse']?->id,
            ), $sellable, array_keys($sellable))),
            currency: $currency,
            customerId: $customer?->id,
            customerGroupId: $customer?->customer_group_id,
            email: $email,
            isFirstOrder: $this->history->isFirstOrder($customer?->id, $email),
            channel: PricingContext::POS,
            couponCode: $couponCode,
            at: $at,
            offline: $at !== null,
        ));

        foreach ($sellable as $position => $line) {
            $priced[$position]['discount_amount'] = $result->lineDiscount($position);
            $priced[$position]['seller_funded_discount_amount'] = $result->lines[$position]['seller_funded_discount_amount'] ?? Money::normalize(0);
        }

        // Tax at the register warehouse's location (§35.2); none without one.
        $inclusive = (bool) $this->settings->get('prices_include_tax', false);
        $address = $warehouse->country_id === null ? null : ['country_id' => $warehouse->country_id, 'state_id' => $warehouse->state_id, 'address_id' => null];
        $taxTotal = Money::normalize(0);
        $positions = array_keys($sellable);

        if ($address !== null && $positions !== []) {
            $taxed = $this->tax->calculateForLines(array_map(static fn (int $p): array => [
                'amount' => Money::sub($priced[$p]['line_subtotal'], $priced[$p]['discount_amount']),
                'tax_class' => (string) ($priced[$p]['product']->tax_class ?: 'standard'),
                'origin' => $warehouse,
            ], $positions), $address, $warehouse, '0', $currency);

            foreach ($positions as $i => $position) {
                $priced[$position]['tax_rate_applied'] = $taxed['lines'][$i]['tax_rate_applied'];
                $priced[$position]['tax_amount'] = $taxed['lines'][$i]['tax_amount'];
                $priced[$position]['tax_breakdown'] = $taxed['lines'][$i]['tax_breakdown'];
            }

            $taxTotal = $taxed['total'];
        }

        $subtotal = $discount = Money::normalize(0);

        foreach ($positions as $position) {
            $priced[$position]['tax_rate_applied'] ??= '0';
            $priced[$position]['tax_amount'] ??= Money::normalize(0);
            $net = Money::sub($priced[$position]['line_subtotal'], $priced[$position]['discount_amount']);
            $priced[$position]['line_total'] = $inclusive ? $net : Money::add($net, $priced[$position]['tax_amount']);
            $subtotal = Money::add($subtotal, $priced[$position]['line_subtotal']);
            $discount = Money::add($discount, $priced[$position]['discount_amount']);
        }

        $total = Money::sub($subtotal, $discount);
        $total = $inclusive ? $total : Money::add($total, $taxTotal);

        foreach ($priced as $line) {
            if ($line['status'] !== Quote::OK) {
                $issues[] = ['code' => $line['status'] === Quote::OUT_OF_STOCK ? 'stock_conflict' : 'item_unavailable', 'message' => "{$line['product']->name} cannot be sold right now."];
            }
        }

        // Points: an order discount on the total, for a known customer (§51.2).
        $points = 0;
        $pointsDiscount = Money::normalize(0);

        if ($rewardPoints > 0) {
            $check = $customer === null ? ['valid' => false, 'reason' => 'customer_required', 'discount' => '0', 'points' => 0]
                : $this->rewardPoints->validateRedemption($customer, $rewardPoints, $total, $currency);

            if ($check['valid']) {
                $points = $check['points'];
                $pointsDiscount = $check['discount'];
                $total = Money::sub($total, $pointsDiscount);
            } else {
                $issues[] = ['code' => 'reward_points_unavailable', 'message' => 'These points cannot be used ('.$check['reason'].').'];
            }
        }

        $totals = [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'shipping_amount' => Money::normalize(0),
            'shipping_discount_amount' => Money::normalize(0),
            'shipping_tax_amount' => Money::normalize(0),
            'tax_amount' => $taxTotal,
            'reward_points_discount_amount' => $pointsDiscount,
            'gift_card_amount_applied' => Money::normalize(0),
            'total' => $total,
            'amount_due' => $total,
        ];

        $hash = hash('sha256', (string) json_encode([
            $register->id,
            array_map(static fn (array $l): array => [$l['product']->id, $l['variant']?->id, $l['quantity'], $l['status'], $l['price']?->unitPrice, $l['discount_amount'], $l['tax_amount']], $priced),
            $totals,
            $couponCode,
            $points,
        ]));

        return new Quote($currency, $priced, $address, false, null, $result, $couponCode, $inclusive, $totals, $issues, $hash, '1', $points);
    }

    /**
     * POST /api/admin/pos/sales (§51.3, §51.6). A repeated idempotency_key
     * returns the sale it created (wasRecentlyCreated = false).
     *
     * @param  array<string, mixed>  $data
     */
    public function createSale(PosRegister $register, array $data, User $by): Order
    {
        $validated = Validator::make($data, [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'lines' => ['required', 'array'],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reward_points' => ['sometimes', 'integer', 'min:0'],
            'credit_sale' => ['sometimes', 'boolean'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::in(self::TENDERS)],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'payments.*.reference' => ['required_if:payments.*.method,card_terminal', 'nullable', 'string', 'max:100'],
            'payments.*.gift_card_code' => ['required_if:payments.*.method,gift_card', 'nullable', 'string', 'max:32'],
            'pos_session_id' => ['sometimes', 'nullable', 'integer'],
            'sold_at' => ['sometimes', 'nullable', 'date'],
            'quote_total' => ['sometimes', 'nullable', 'numeric'],
            'cashier_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.users', 'id')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        // §51.6: a synced offline sale never becomes a second order.
        if (($existing = Order::query()->where('idempotency_key', $validated['idempotency_key'])->first()) !== null) {
            if ($existing->order_source !== 'pos') {
                throw ApiException::conflict('idempotency_key_reused', 'This idempotency key belongs to another request.');
            }

            return $existing;
        }

        $settings = $this->posSettings->getSettings();
        $payments = array_values($validated['payments']);
        $creditSale = (bool) ($validated['credit_sale'] ?? false);
        $points = (int) ($validated['reward_points'] ?? 0);

        if (! $register->is_active) {
            throw ApiException::unprocessable('register_inactive', 'This register is inactive.');
        }

        foreach ([...array_column($payments, 'method'), ...($creditSale ? ['credit_sale'] : []), ...($points > 0 ? ['reward_points'] : [])] as $method) {
            if (! in_array($method, $settings->enabled_payment_methods, true)) {
                throw ApiException::unprocessable('payment_method_disabled', "The {$method} payment method is not enabled for POS.", ['method' => $method]);
            }
        }

        if (count(array_keys(array_column($payments, 'method'), 'cash', true)) > 1) {
            throw ApiException::unprocessable('payment_invalid', 'Enter the cash tendered as one payment.');
        }

        $at = $this->saleTime($validated['sold_at'] ?? null);
        $session = $this->resolveSession($register, $settings->cash_register_enabled, $validated['pos_session_id'] ?? null, $at);
        $customer = $this->resolveCustomer($validated['customer_id'] ?? null, $settings->default_customer_id);
        $known = $customer !== null && $customer->id !== $settings->default_customer_id;

        if (($creditSale || $points > 0) && ! $known) {
            throw ApiException::unprocessable('customer_required', 'Choose the customer: credit sales and reward points need a real customer, not the walk-in.');
        }

        $quote = $this->quote($register, $validated['lines'], $customer, $validated['coupon_code'] ?? null, $points, $at);
        $codes = array_column($quote->issues, 'code');

        if (in_array('stock_conflict', $codes, true)) {
            throw ApiException::conflict('stock_conflict', 'An item on this sale is not in stock at this register.', ['quote' => $quote->toArray()]);
        }

        if ($quote->issues !== []) {
            throw ApiException::unprocessable($codes[0], $quote->issues[0]['message'], ['quote' => $quote->toArray()]);
        }

        $total = (string) $quote->totals['total'];

        if (isset($validated['quote_total']) && Money::cmp(Money::normalize((string) $validated['quote_total']), $total) !== 0) {
            throw ApiException::conflict('totals_changed', 'The sale total changed. Review the new total.', ['quote' => $quote->toArray()]);
        }

        $plan = $this->planTenders($payments, $total, $creditSale);

        // Card payments are re-verified with the provider before the commit (§51.3 step 4).
        foreach ($plan as $tender) {
            if ($tender['method'] === 'card_terminal') {
                $this->terminals->assertLiveMode();
                $this->terminals->verifyCharge($register, (string) $tender['reference']);
            }
        }

        $cashierId = $validated['cashier_user_id'] ?? $settings->default_cashier_user_id ?? $by->id;
        $offline = $at !== null;

        $order = DB::connection('tenant')->transaction(function () use ($register, $validated, $quote, $customer, $session, $plan, $at, $offline, $cashierId, $by): Order {
            $order = $this->orders->createOrder([
                'order_source' => 'pos',
                'status' => Order::COMPLETED,
                'customer' => $customer,
                'customer_name' => $customer?->name,
                'customer_email' => $customer?->email,
                'customer_phone' => $customer?->phone,
                'currency_code' => $quote->currency,
                'exchange_rate' => '1',
                'reward_points_redeemed' => $quote->rewardPoints,
                'prices_include_tax' => $quote->pricesIncludeTax,
                'lines' => array_map(static fn (array $l): array => [
                    'product' => $l['product'], 'variant' => $l['variant'], 'warehouse' => $l['warehouse'], 'quantity' => $l['quantity'],
                    'unit_price' => $l['price']->unitPrice, 'price_source' => $l['price']->source, 'discount_amount' => $l['discount_amount'],
                    'seller_funded_discount_amount' => $l['seller_funded_discount_amount'], 'tax_rate_applied' => $l['tax_rate_applied'],
                    'tax_amount' => $l['tax_amount'], 'tax_breakdown' => $l['tax_breakdown'], 'line_total' => $l['line_total'],
                ], $quote->lines),
                'totals' => $quote->totals,
                'created_by_user_id' => $cashierId,
                'idempotency_key' => $validated['idempotency_key'],
                'customer_note' => $validated['notes'] ?? null,
                'pos_session_id' => $session?->id,
                'placed_at' => $at ?? now(),
                'one_pass' => true,
            ]);

            $this->flashSales->claim($order);
            $this->redemptions->reserve($order, $quote->promotions, $at, $offline);

            foreach ($plan as $tender) {
                $this->recordTender($order, $register, $session, $tender, $by, $at);
            }

            $this->rewardPoints->redeemPoints($order, $quote->rewardPoints);
            // Stock leaves the register warehouse directly; redemptions commit (§32.5).
            $this->orders->confirmOrder($order, true, false);
            $this->orders->recalculatePaymentStatus($order);

            if ($session !== null && $session->status === PosSession::CLOSED) {
                $this->sessions->refreshClosedTotals($session);
            }

            return $order;
        });

        $this->sendReceipt($order, $settings->send_sms_after_sale && $known);

        return $order;
    }

    /**
     * §51.3 Void: only while the sale's session is open (A-34); without
     * sessions, within pos.void_window_hours. Every payment except gift
     * cards is refunded first (card terminals through their driver), then
     * the sale is undone. Retrying is safe: each payment is refunded only
     * for what is left of it (the refund locks the payment row).
     */
    public function voidSale(Order $order, string $reason, User $by, bool $reversedOnTerminal = false): Order
    {
        // A retry after the refunds went through finds the sale already marked refunded.
        if ($order->order_source !== 'pos' || $order->cancelled_at !== null || ! in_array($order->status, [Order::COMPLETED, Order::REFUNDED], true)) {
            throw ApiException::unprocessable('sale_not_voidable', 'Only a completed POS sale can be voided.');
        }

        $session = $order->pos_session_id === null ? null : PosSession::query()->find($order->pos_session_id);
        $open = $session !== null ? $session->status === PosSession::OPEN
            : $order->placed_at->gt(now()->subHours((int) config('pos.void_window_hours', 24)));

        if (! $open) {
            throw ApiException::unprocessable('void_window_closed', 'This sale\'s session is closed: use a return instead.');
        }

        $reason = trim($reason) === '' ? 'Voided' : $reason;
        $payments = OrderPayment::query()->where('order_id', $order->id)->where('kind', OrderPayment::PAYMENT)
            ->where('status', OrderPayment::SUCCESSFUL)->where('payment_method', '!=', 'gift_card')->orderBy('id')->get();

        foreach ($payments as $payment) {
            $left = $this->payments->capacity($payment);

            if (Money::isPositive($left)) {
                $this->payments->refund($payment, $left, 'Void: '.$reason, $by, null, null, $reversedOnTerminal);
            }
        }

        // A card refund still in flight, or one the provider refused, stops the void.
        $pending = OrderPayment::query()->whereIn('refund_of_order_payment_id', $payments->pluck('id'))->where('status', OrderPayment::PENDING)->get();
        $unrefunded = $payments->filter(fn (OrderPayment $p): bool => Money::isPositive($this->payments->capacity($p)));

        if ($pending->isNotEmpty() || $unrefunded->isNotEmpty()) {
            throw ApiException::conflict('void_refund_incomplete', 'A refund did not complete. Check the terminal, then retry the void.', [
                'pending_refund_ids' => $pending->pluck('id')->values()->all(),
                'unrefunded_payment_ids' => $unrefunded->pluck('id')->values()->all(),
            ]);
        }

        return $this->orders->voidOrder($order, $reason);
    }

    /**
     * Structured receipt data (§51.3 step 6), with the warehouse's printer
     * when one is configured (§43.6); otherwise the browser prints it.
     *
     * @return array<string, mixed>
     */
    public function generateReceipt(Order $order): array
    {
        $order->loadMissing('items.warehouse');
        $cashier = $order->created_by_user_id === null ? null : User::query()->find($order->created_by_user_id, ['id', 'name']);
        $payments = OrderPayment::query()->where('order_id', $order->id)->where('kind', OrderPayment::PAYMENT)->where('status', OrderPayment::SUCCESSFUL)->orderBy('id')->get();
        $session = $order->pos_session_id === null ? null : PosSession::query()->with('register.warehouse')->find($order->pos_session_id);
        $warehouse = $session?->register->warehouse ?? $order->items->first(static fn (OrderItem $i): bool => $i->warehouse_id !== null)?->warehouse;
        $printer = $warehouse === null ? null : $this->printers->getPrinterForWarehouse($warehouse);
        $money = static fn (mixed $v): string => (string) $v;

        return [
            'store_name' => (string) $this->settings->get('store_name', ''),
            'is_test' => $order->is_test,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'invoice_number' => $order->invoice_number,
            'placed_at' => $order->placed_at->toIso8601String(),
            'status' => $order->status,
            'register' => $session === null ? null : ['id' => $session->register->id, 'name' => $session->register->name],
            'warehouse' => $warehouse === null ? null : ['id' => $warehouse->id, 'name' => $warehouse->name],
            'cashier' => $cashier === null ? null : ['id' => $cashier->id, 'name' => $cashier->name],
            'customer' => $order->customer_id === null ? null : ['id' => $order->customer_id, 'name' => $order->customer_name],
            'currency_code' => $order->currency_code,
            'lines' => $order->items->map(static fn (OrderItem $i): array => [
                'name' => $i->name_snapshot,
                'sku' => $i->sku_snapshot,
                'quantity' => (string) $i->quantity,
                'unit_price' => (string) $i->unit_price,
                'discount_amount' => (string) $i->discount_amount,
                'tax_amount' => (string) $i->tax_amount,
                'line_total' => (string) $i->line_total,
            ])->values()->all(),
            'subtotal' => $money($order->subtotal),
            'discount_amount' => $money($order->discount_amount),
            'tax_amount' => $money($order->tax_amount),
            'reward_points_redeemed' => $order->reward_points_redeemed,
            'reward_points_discount_amount' => $money($order->reward_points_discount_amount),
            'total' => $money($order->total),
            'payment_status' => $order->payment_status,
            'payments' => $payments->map(static fn (OrderPayment $p): array => [
                'method' => $p->payment_method,
                'amount_paid' => (string) $p->amount_paid,
                'amount_received' => $p->amount_received === null ? null : (string) $p->amount_received,
                'change_given' => $p->change_given === null ? null : (string) $p->change_given,
                'card_last4' => $p->meta['card_last4'] ?? null,
                'gift_card' => $p->meta['gift_card'] ?? null,
            ])->values()->all(),
            'printer' => $printer === null ? null : app(DocumentPresenter::class)->printer($printer),
            'print_by_default' => $this->posSettings->getSettings()->print_receipt_by_default,
        ];
    }

    /**
     * GET /api/admin/pos/products/lookup (§51.3 step 2): a barcode checks
     * variants first, then products (unique across both, §28.2); q searches
     * name, SKU and barcode. With a register, price and stock are its own.
     *
     * @return list<array<string, mixed>>
     */
    public function lookupProducts(?string $barcode, ?string $query, ?PosRegister $register = null, int $limit = 20): array
    {
        $rows = [];

        if ($barcode !== null && $barcode !== '') {
            $variant = ProductVariant::query()->with('product')->where('barcode', $barcode)->first();

            if ($variant !== null && $variant->product !== null) {
                $rows[] = [$variant->product, $variant];
            } elseif (($product = Product::query()->where('barcode', $barcode)->first()) !== null) {
                $rows[] = [$product, null];
            }
        } elseif ($query !== null && trim($query) !== '') {
            $like = '%'.addcslashes(trim($query), '%_\\').'%';

            foreach (Product::query()->where(static fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', $query))
                ->where('is_active', true)->orderBy('name')->limit($limit)->get() as $product) {
                $rows[] = [$product, null];
            }
        }

        $register?->loadMissing('warehouse');
        $currency = $this->currencies->baseCurrency();

        return array_values(array_map(function (array $row) use ($register, $currency): array {
            /** @var Product $product */
            [$product, $variant] = $row;
            $warehouse = $register !== null && $product->isPhysical() ? $register->warehouse : null;
            $sellable = CheckoutService::sellable($product, $variant) || ($variant === null && $product->product_type === Product::VARIABLE);
            $price = $sellable && ($variant !== null || $product->product_type !== Product::VARIABLE)
                ? $this->pricing->resolveUnitPrice($product, $variant, $warehouse, $currency, '1') : null;

            return [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'name' => $product->name.($variant === null ? '' : ' ('.$variant->sku.')'),
                'sku' => $variant?->sku ?? $product->sku,
                'barcode' => $variant?->barcode ?? $product->barcode,
                'product_type' => $product->product_type,
                'requires_variant' => $variant === null && $product->product_type === Product::VARIABLE,
                'sellable' => $sellable,
                'price' => $price?->unitPrice,
                'currency_code' => $currency,
                'stock' => $warehouse === null ? null : $this->inventory->getAvailableStock($product, $variant, $warehouse),
            ];
        }, $rows));
    }

    public function initiateTerminalCharge(PosRegister $register, string $amount, ?User $by = null): PosTerminalCharge
    {
        return $this->terminals->initiateCharge($register, $amount, $by);
    }

    public function verifyTerminalCharge(PosRegister $register, string $reference): PosTerminalCharge
    {
        return $this->terminals->verifyCharge($register, $reference);
    }

    /**
     * @param  array{register_id?: int, session_id?: int, cashier_id?: int, status?: string, from?: string, to?: string, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function listSales(array $filters = []): LengthAwarePaginator
    {
        return Order::query()->withCount('items')->where('order_source', 'pos')
            ->when(isset($filters['session_id']), static fn ($q) => $q->where('pos_session_id', $filters['session_id']))
            ->when(isset($filters['register_id']), static fn ($q) => $q->whereIn('pos_session_id', PosSession::query()->select('id')->where('pos_register_id', $filters['register_id'])))
            ->when(isset($filters['cashier_id']), static fn ($q) => $q->where('created_by_user_id', $filters['cashier_id']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('placed_at', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('placed_at', '<=', $filters['to']))
            ->when(isset($filters['search']), static fn ($q) => $q->where('order_number', $filters['search']))
            ->orderByDesc('placed_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * How each tender pays (§51.2): non-cash tenders never exceed the total;
     * cash covers the rest and any excess is change. Without credit_sale,
     * the tenders must cover the total.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return list<array{method: string, amount: string, received: string|null, change: string|null, reference: string|null, gift_card_code: string|null}>
     */
    private function planTenders(array $payments, string $total, bool $creditSale): array
    {
        $currency = $this->currencies->baseCurrency();
        $plan = [];
        $nonCash = Money::normalize(0);

        foreach ($payments as $payment) {
            if ($payment['method'] !== 'cash') {
                $amount = Money::round(Money::normalize((string) $payment['amount']), $currency);
                $nonCash = Money::add($nonCash, $amount);
                $plan[] = ['method' => $payment['method'], 'amount' => $amount, 'received' => null, 'change' => null,
                    'reference' => $payment['reference'] ?? null, 'gift_card_code' => $payment['gift_card_code'] ?? null];
            }
        }

        if (Money::cmp($nonCash, $total) > 0) {
            throw ApiException::unprocessable('payment_exceeds_total', 'Only cash can be more than the total (the difference is change).');
        }

        $paid = $nonCash;

        foreach ($payments as $payment) {
            if ($payment['method'] === 'cash') {
                $tendered = Money::round(Money::normalize((string) $payment['amount']), $currency);
                $applied = Money::min($tendered, Money::sub($total, $nonCash));

                if (! Money::isPositive($applied)) {
                    throw ApiException::unprocessable('payment_exceeds_total', 'Nothing is left to pay in cash.');
                }

                $paid = Money::add($paid, $applied);
                $plan[] = ['method' => 'cash', 'amount' => $applied, 'received' => $tendered, 'change' => Money::sub($tendered, $applied), 'reference' => null, 'gift_card_code' => null];
            }
        }

        if (! $creditSale && Money::cmp($paid, $total) < 0) {
            throw ApiException::unprocessable('payment_insufficient', 'The payments do not cover the total.', ['total' => $total, 'paid' => $paid, 'due' => Money::sub($total, $paid)]);
        }

        return $plan;
    }

    /**
     * @param  array{method: string, amount: string, received: string|null, change: string|null, reference: string|null, gift_card_code: string|null}  $tender
     */
    private function recordTender(Order $order, PosRegister $register, ?PosSession $session, array $tender, User $by, ?CarbonInterface $at): void
    {
        if ($tender['method'] === 'gift_card') {
            $card = GiftCard::query()->where('code', strtoupper(trim((string) $tender['gift_card_code'])))->first();

            if ($card === null || ! $this->giftCards->enabled()) {
                throw ApiException::unprocessable('gift_card_invalid', 'This gift card cannot be used.', ['reason' => $card === null ? 'not_found' : 'unavailable']);
            }

            $row = $this->giftCards->redeemGiftCard($card, $order, $tender['amount']);
            $row->forceFill(['pos_session_id' => $session?->id, 'recorded_by_user_id' => $by->id])->save();

            return;
        }

        $charge = $tender['method'] === 'card_terminal' ? $this->terminals->claimForSale($register, (string) $tender['reference'], $tender['amount']) : null;

        $row = new OrderPayment;
        $row->forceFill([
            'order_id' => $order->id,
            'kind' => OrderPayment::PAYMENT,
            'payment_method' => $tender['method'],
            'provider' => $charge?->provider,
            'mode' => OrderPaymentService::modeOf($order),
            'status' => OrderPayment::SUCCESSFUL,
            'reference' => 'POS-'.$order->order_number.'-'.Str::upper(Str::random(8)),
            'provider_reference' => $charge?->provider_reference ?? ($tender['method'] === 'card_terminal' ? null : $tender['reference']),
            'amount_due' => $tender['amount'],
            'amount_received' => $tender['received'],
            'amount_paid' => $tender['amount'],
            'change_given' => $tender['change'],
            'currency_code' => $order->currency_code,
            'pos_session_id' => $session?->id,
            'recorded_by_user_id' => $by->id,
            'paid_at' => $at ?? now(),
            'meta' => $charge === null ? null : ['pos_register_id' => $register->id, 'terminal_reference' => $charge->reference, 'card_last4' => $charge->card_last4],
        ])->save();

        $charge?->forceFill(['order_payment_id' => $row->id])->save();
        $this->postings->payment($row);
    }

    /**
     * An offline sale's own time (§51.6): not in the future, not older than
     * pos.offline_sale_max_age_hours. Null for a live sale.
     */
    private function saleTime(?string $soldAt): ?CarbonImmutable
    {
        if ($soldAt === null) {
            return null;
        }

        $at = CarbonImmutable::parse($soldAt);

        if ($at->gt(now()->addMinutes(5)) || $at->lt(now()->subHours((int) config('pos.offline_sale_max_age_hours', 72)))) {
            throw ApiException::unprocessable('sale_time_invalid', 'The sale time is in the future or too old to sync. Enter it as a new sale.');
        }

        return $at;
    }

    /**
     * With cash sessions on, a sale needs the register's open session; an
     * offline sale may name the session it was made in, even if it has
     * closed since, when its time falls inside that session (§51.6).
     */
    private function resolveSession(PosRegister $register, bool $required, ?int $sessionId, ?CarbonInterface $at): ?PosSession
    {
        if ($sessionId !== null) {
            $session = PosSession::query()->where('pos_register_id', $register->id)->find($sessionId)
                ?? throw ApiException::unprocessable('session_invalid', 'This session is not on this register.');

            if ($session->status === PosSession::OPEN) {
                return $session;
            }

            if ($at !== null && $at->gte($session->opened_at) && $at->lte($session->closed_at)) {
                return $session;
            }

            throw ApiException::conflict('session_closed', 'This session is closed.');
        }

        $current = $this->sessions->getCurrentSession($register);

        if ($required && $current === null) {
            throw ApiException::unprocessable('session_required', 'Open a session on this register first.');
        }

        return $current;
    }

    private function resolveCustomer(?int $customerId, ?int $defaultId): ?Customer
    {
        if ($customerId !== null) {
            return Customer::query()->find($customerId) ?? throw ApiException::unprocessable('customer_invalid', 'This customer does not exist.');
        }

        return $defaultId === null ? null : Customer::query()->find($defaultId);
    }

    /**
     * send_sms_after_sale (§51.3 step 6): the receipt goes to a known
     * customer by the template's channels (SMS and e-mail) when they have
     * contact details.
     */
    private function sendReceipt(Order $order, bool $send): void
    {
        if (! $send || ($order->customer_email === null && $order->customer_phone === null)) {
            return;
        }

        $this->notifications->dispatch('pos.sale_receipt', $order, [
            'customer_name' => (string) ($order->customer_name ?? ''),
            'order_number' => $order->order_number,
            'store_name' => (string) $this->settings->get('store_name', ''),
            'order_total' => Money::format((string) $order->total, $order->currency_code),
            'sale_date' => $order->placed_at->toDateString(),
        ]);
    }
}
