<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\PricingService;
use App\Modules\Cart\Support\PriceResult;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Support\Quote;
use App\Modules\Customers\Models\Address;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Jobs\ExpireUnpaidOrder;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Modules\Promotions\Services\PromotionEngine;
use App\Modules\Promotions\Services\PromotionRedemptionService;
use App\Modules\Promotions\Support\BuyerHistory;
use App\Modules\Promotions\Support\PricingContext;
use App\Modules\Promotions\Support\PricingLine;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Services\ShippingService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checkout (spec §38.6). quote() runs steps 1–8 with no writes: it backs
 * the cart totals and is re-run by placeOrder(), whose commit is one short
 * transaction with no network calls.
 */
final readonly class CheckoutService
{
    public function __construct(
        private PricingService $pricing,
        private InventoryService $inventory,
        private PromotionEngine $promotions,
        private ShippingService $shipping,
        private TaxService $tax,
        private TenantSettingsService $settings,
        private BuyerHistory $history,
        private OrderService $orders,
        private FlashSaleService $flashSales,
        private PromotionRedemptionService $redemptions,
        private PlatformSettingsService $platformSettings,
    ) {}

    /**
     * POST /api/orders (§38.6). A repeated Idempotency-Key returns the
     * order it created. The client sends the quote_hash it displayed; a
     * different recomputed quote is 409 totals_changed with the fresh quote.
     *
     * @param  array<string, mixed>  $data  quote_hash, address_id | shipping_address{…}, billing_address, shipping_method_id, guest_email, guest_phone, gateway, customer_note
     */
    public function placeOrder(Cart $cart, array $data, ?string $idempotencyKey = null): Order
    {
        if ($idempotencyKey !== null && ($existing = Order::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        $cart->loadMissing(['customer', 'items']);
        $customer = $cart->customer;

        if ($cart->items->isEmpty()) {
            throw ApiException::unprocessable('cart_empty', 'The cart is empty.');
        }

        // 1. The buyer.
        $validated = validator($data, [
            'quote_hash' => ['required', 'string', 'size:64'],
            'guest_email' => [$customer === null ? 'required' : 'sometimes', 'email:rfc', 'max:255'],
            'guest_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'guest_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shipping_address' => ['sometimes', 'array'],
            'shipping_address.name' => ['required_with:shipping_address', 'string', 'max:120'],
            'shipping_address.phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'shipping_address.line1' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shipping_address.city_id' => ['sometimes', 'nullable', 'integer'],
            'shipping_address.state_id' => ['sometimes', 'nullable', 'integer'],
            'shipping_address.country_id' => ['required_with:shipping_address', 'integer'],
            'shipping_address.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'billing_address' => ['sometimes', 'nullable', 'array'],
            'gateway' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_keys((array) config('payments.providers')))],
            'customer_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        if ($customer === null && ! ((bool) $this->settings->get('guest_checkout_enabled', true) && (bool) $this->platformSettings->get('guest_checkout_allowed_platform_wide', true))) {
            throw ApiException::forbidden('guest_checkout_disabled', 'Sign in to place an order.');
        }

        if (isset($validated['shipping_address'])) {
            $data['country_id'] = $validated['shipping_address']['country_id'];
            $data['state_id'] = $validated['shipping_address']['state_id'] ?? null;
            unset($data['address_id']);
        }

        // 2–8. The quote, exactly as the cart shows it.
        $quote = $this->quote($cart, $data);

        if ($quote->requiresShipping && $quote->address === null) {
            throw ApiException::unprocessable('shipping_address_required', 'Enter a shipping address.');
        }

        $codes = array_column($quote->issues, 'code');

        if (in_array('stock_conflict', $codes, true)) {
            throw ApiException::conflict('stock_conflict', 'An item on this basket is no longer in stock.', ['quote' => $quote->toArray()]);
        }

        if ($quote->issues !== []) {
            throw ApiException::unprocessable($codes[0], $quote->issues[0]['message'], ['quote' => $quote->toArray()]);
        }

        // 9. The price guard.
        if (! hash_equals($quote->hash, (string) $validated['quote_hash'])) {
            throw ApiException::conflict('totals_changed', 'The basket total changed. Review the new total.', ['quote' => $quote->toArray()]);
        }

        $shippingAddress = $this->addressSnapshot($cart, $data, $validated);

        // 11. The commit: no network calls inside.
        $order = DB::connection('tenant')->transaction(function () use ($cart, $quote, $customer, $validated, $shippingAddress, $idempotencyKey): Order {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

            $order = $this->orders->createOrder([
                'order_source' => 'online',
                'customer' => $customer,
                'guest_token' => $customer === null ? $cart->guest_token : null,
                'customer_name' => $customer?->name ?? ($validated['guest_name'] ?? $shippingAddress['name'] ?? null),
                'customer_email' => $customer?->email ?? $validated['guest_email'],
                'customer_phone' => $customer?->phone ?? ($validated['guest_phone'] ?? $shippingAddress['phone'] ?? null),
                'currency_code' => $quote->currency,
                'prices_include_tax' => $quote->pricesIncludeTax,
                'lines' => array_values(array_map(static fn (array $l): array => [
                    'product' => $l['product'],
                    'variant' => $l['variant'],
                    'warehouse' => $l['warehouse'],
                    'quantity' => $l['quantity'],
                    'unit_price' => $l['price']->unitPrice,
                    'price_source' => $l['price']->source,
                    'discount_amount' => $l['discount_amount'],
                    'seller_funded_discount_amount' => $l['seller_funded_discount_amount'],
                    'tax_rate_applied' => $l['tax_rate_applied'] ?? '0',
                    'tax_amount' => $l['tax_amount'] ?? '0',
                    'tax_breakdown' => $l['tax_breakdown'],
                    'line_total' => $l['line_total'],
                ], array_filter($quote->lines, static fn (array $l): bool => $l['status'] === Quote::OK))),
                'totals' => $quote->totals,
                'shipping_method_id' => $quote->shippingMethod?->id,
                'shipping_address' => $shippingAddress,
                'billing_address' => $validated['billing_address'] ?? $shippingAddress,
                'payment_gateway' => $validated['gateway'] ?? null,
                'customer_note' => $validated['customer_note'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'expires' => true,
            ]);

            $this->flashSales->claim($order);
            $this->redemptions->reserve($order, $quote->promotions);
            $this->orders->recalculatePaymentStatus($order);

            $cart->items()->delete();
            $cart->forceFill(['coupon_id' => null, 'gift_card_id' => null, 'reward_points_to_redeem' => null, 'last_activity_at' => now()])->save();

            return $order;
        });

        // 12. After commit: an unpaid order expires on its own.
        if ($order->payment_expires_at !== null) {
            ExpireUnpaidOrder::dispatch((string) tenant()?->getTenantKey(), $order->id)->delay($order->payment_expires_at);
        }

        return $order;
    }

    /**
     * duplicateOrder (§39.5): the same customer and lines as a new pending,
     * unpaid admin order, priced and promoted at today's values. Lives here
     * because it re-quotes through checkout.
     */
    public function duplicateOrder(Order $order, User $by): Order
    {
        $order->loadMissing(['items.product', 'items.variant', 'customer']);

        $cart = new Cart;
        $cart->forceFill(['customer_id' => $order->customer_id, 'currency_code' => $order->currency_code]);
        $cart->setRelation('customer', $order->customer);
        $cart->setRelation('coupon', null);
        $cart->setRelation('items', $order->items->filter(static fn (OrderItem $i): bool => $i->product !== null)->map(static function (OrderItem $i): CartItem {
            $item = new CartItem(['product_id' => $i->product_id, 'product_variant_id' => $i->product_variant_id, 'quantity' => (string) $i->quantity]);
            $item->setRelation('product', $i->product);
            $item->setRelation('variant', $i->variant);

            return $item;
        })->values());

        $address = $order->shipping_address;
        $quote = $this->quote($cart, $address === null ? [] : [
            'country_id' => $address['country_id'] ?? null,
            'state_id' => $address['state_id'] ?? null,
            'shipping_method_id' => $order->shipping_method_id,
        ]);

        if ($quote->issues !== []) {
            throw ApiException::unprocessable($quote->issues[0]['code'], $quote->issues[0]['message'], ['quote' => $quote->toArray()]);
        }

        return DB::connection('tenant')->transaction(function () use ($order, $quote, $by): Order {
            $copy = $this->orders->createOrder([
                'order_source' => 'admin',
                'status' => Order::PENDING,
                'customer' => $order->customer,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'customer_phone' => $order->customer_phone,
                'currency_code' => $quote->currency,
                'prices_include_tax' => $quote->pricesIncludeTax,
                'lines' => array_values(array_map(static fn (array $l): array => [
                    'product' => $l['product'], 'variant' => $l['variant'], 'warehouse' => $l['warehouse'], 'quantity' => $l['quantity'],
                    'unit_price' => $l['price']->unitPrice, 'price_source' => $l['price']->source, 'discount_amount' => $l['discount_amount'],
                    'seller_funded_discount_amount' => $l['seller_funded_discount_amount'], 'tax_rate_applied' => $l['tax_rate_applied'] ?? '0',
                    'tax_amount' => $l['tax_amount'] ?? '0', 'tax_breakdown' => $l['tax_breakdown'], 'line_total' => $l['line_total'],
                ], $quote->lines)),
                'totals' => $quote->totals,
                'shipping_method_id' => $quote->shippingMethod?->id,
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
                'created_by_user_id' => $by->id,
            ]);

            $this->flashSales->claim($copy);
            $this->redemptions->reserve($copy, $quote->promotions);
            $this->orders->recalculatePaymentStatus($copy);

            return $copy;
        });
    }

    /**
     * @param  array<string, mixed>  $data  address_id | address{country_id, state_id}, shipping_method_id, guest_email
     */
    public function quote(Cart $cart, array $data = []): Quote
    {
        $cart->loadMissing(['items.product.unit', 'items.variant', 'coupon', 'customer']);
        $currency = $cart->currency_code;
        $customer = $cart->customer;
        $address = $this->resolveAddress($cart, $data);
        $issues = [];

        // 2, 4, 5: validate lines, pick fulfilment warehouses, price.
        $lines = [];

        foreach ($cart->items as $item) {
            $lines[] = $this->priceLine($item, $currency);
        }

        $sellable = array_filter($lines, static fn (array $l): bool => $l['status'] === Quote::OK);
        $requiresShipping = array_filter($sellable, static fn (array $l): bool => $l['product']->isPhysical()) !== [];

        // 3: shipping, when an address is known.
        $method = null;
        $shippingAmount = null;

        if ($address !== null && $requiresShipping && ! empty($data['shipping_method_id'])) {
            $method = $this->shipping->getAvailableMethods($address)->firstWhere('id', (int) $data['shipping_method_id']);

            if ($method === null) {
                $issues[] = ['code' => 'shipping_method_unavailable', 'message' => 'The shipping method is not available for this address.'];
            } else {
                $shippingAmount = Money::round($this->shipping->calculateShippingCost($method, array_map(static fn (array $l): Product => $l['product'], $sellable)), $currency);
            }
        } elseif ($address !== null && ! $requiresShipping) {
            $shippingAmount = Money::normalize(0);
        }

        // 6: promotions.
        $email = $customer?->email ?? (isset($data['guest_email']) ? (string) $data['guest_email'] : null);
        $result = $this->promotions->evaluate(new PricingContext(
            lines: array_values(array_map(static fn (array $l, int $position): PricingLine => new PricingLine(
                $position, $l['product'], $l['variant'], $l['quantity'], $l['price']->unitPrice, $l['price']->source, $l['warehouse']?->id,
            ), $sellable, array_keys($sellable))),
            currency: $currency,
            customerId: $customer?->id,
            customerGroupId: $customer?->customer_group_id,
            email: $email,
            isFirstOrder: $this->history->isFirstOrder($customer?->id, $email),
            channel: PricingContext::ONLINE,
            couponCode: $cart->coupon?->code,
            shippingAmount: $shippingAmount,
        ));

        foreach ($sellable as $position => $line) {
            $lines[$position]['discount_amount'] = $result->lineDiscount($position);
            $lines[$position]['seller_funded_discount_amount'] = $result->lines[$position]['seller_funded_discount_amount'] ?? Money::normalize(0);
        }

        // 8: tax and totals (§38.5).
        $inclusive = (bool) $this->settings->get('prices_include_tax', false);
        $shippingDiscount = $shippingAmount === null ? Money::normalize(0) : Money::min($result->shippingDiscountAmount, $shippingAmount);
        $taxTotal = null;
        $shippingTax = null;

        if ($address !== null) {
            $positions = array_keys($sellable);
            $taxed = $this->tax->calculateForLines(
                array_map(static fn (int $p): array => [
                    'amount' => Money::sub($lines[$p]['line_subtotal'], $lines[$p]['discount_amount']),
                    'tax_class' => (string) ($lines[$p]['product']->tax_class ?: 'standard'),
                    'origin' => $lines[$p]['warehouse'],
                ], $positions),
                $address,
                null,
                $shippingAmount === null ? '0' : Money::sub($shippingAmount, $shippingDiscount),
                $currency,
            );

            foreach ($positions as $i => $position) {
                $lines[$position]['tax_rate_applied'] = $taxed['lines'][$i]['tax_rate_applied'];
                $lines[$position]['tax_amount'] = $taxed['lines'][$i]['tax_amount'];
                $lines[$position]['tax_breakdown'] = $taxed['lines'][$i]['tax_breakdown'];
            }

            $shippingTax = $taxed['shipping_tax_amount'];
            $taxTotal = $taxed['total'];
        }

        $subtotal = $discount = Money::normalize(0);

        foreach ($sellable as $position => $line) {
            $net = Money::sub($lines[$position]['line_subtotal'], $lines[$position]['discount_amount']);
            $lineTax = $lines[$position]['tax_amount'];
            $lines[$position]['line_total'] = $lineTax === null ? $net : ($inclusive ? $net : Money::add($net, $lineTax));
            $subtotal = Money::add($subtotal, $lines[$position]['line_subtotal']);
            $discount = Money::add($discount, $lines[$position]['discount_amount']);
        }

        $total = Money::sub($subtotal, $discount);

        if ($shippingAmount !== null) {
            $total = Money::sub(Money::add($total, $shippingAmount), $shippingDiscount);
        }

        if ($taxTotal !== null && ! $inclusive) {
            $total = Money::add($total, $taxTotal);
        }

        foreach ($lines as $line) {
            if ($line['status'] !== Quote::OK) {
                $issues[] = ['code' => $line['status'] === Quote::OUT_OF_STOCK ? 'stock_conflict' : 'item_unavailable', 'message' => "{$line['product']->name} cannot be bought right now."];
            }
        }

        if ($requiresShipping && $address !== null && $method === null && ! in_array('shipping_method_unavailable', array_column($issues, 'code'), true)) {
            $issues[] = ['code' => 'shipping_method_required', 'message' => 'Choose a shipping method.'];
        }

        $totals = [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'shipping_amount' => $shippingAmount,
            'shipping_discount_amount' => $shippingAmount === null ? null : $shippingDiscount,
            'shipping_tax_amount' => $shippingTax,
            'tax_amount' => $taxTotal,
            'reward_points_discount_amount' => Money::normalize(0),
            'gift_card_amount_applied' => Money::normalize(0),
            'total' => $total,
            'amount_due' => $total,
        ];

        return new Quote($currency, array_values($lines), $address, $requiresShipping, $method, $result, $cart->coupon?->code,
            $inclusive, $totals, $issues, $this->hash($currency, $lines, $totals, $cart->coupon?->code, $method?->id, $address));
    }

    /**
     * The shipping address snapshot (§39.1 shape): from the customer's
     * saved address or the inline one; null when nothing ships.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private function addressSnapshot(Cart $cart, array $data, array $validated): ?array
    {
        if (isset($validated['shipping_address'])) {
            $a = $validated['shipping_address'];

            return [
                'name' => $a['name'], 'phone' => $a['phone'] ?? null, 'line1' => $a['line1'], 'line2' => $a['line2'] ?? null,
                'city_id' => $a['city_id'] ?? null, 'state_id' => $a['state_id'] ?? null, 'country_id' => (int) $a['country_id'], 'postal_code' => $a['postal_code'] ?? null,
            ];
        }

        if (! empty($data['address_id']) && $cart->customer_id !== null) {
            $address = Address::query()->where('customer_id', $cart->customer_id)->whereKey((int) $data['address_id'])->first();

            return $address === null ? null : [
                'name' => $address->recipient_name, 'phone' => $address->phone, 'line1' => $address->address_line_1, 'line2' => $address->address_line_2,
                'city_id' => $address->city_id, 'state_id' => $address->state_id, 'country_id' => $address->country_id, 'postal_code' => $address->postal_code,
            ];
        }

        return null;
    }

    /**
     * The customer's own saved address, or an inline one (country and
     * state are what pricing needs).
     *
     * @param  array<string, mixed>  $data
     * @return array{country_id: int|null, state_id: int|null, address_id: int|null}|null
     */
    public function resolveAddress(Cart $cart, array $data): ?array
    {
        if (! empty($data['address_id'])) {
            $address = $cart->customer_id === null ? null
                : Address::query()->where('customer_id', $cart->customer_id)->whereKey((int) $data['address_id'])->first();

            if ($address === null) {
                throw ValidationException::withMessages(['address_id' => ['Choose one of your saved addresses.']]);
            }

            return ['country_id' => $address->country_id, 'state_id' => $address->state_id, 'address_id' => $address->id];
        }

        if (empty($data['country_id'])) {
            return null;
        }

        $country = (int) $data['country_id'];
        $state = empty($data['state_id']) ? null : (int) $data['state_id'];

        if (! DB::connection('landlord')->table('countries')->where('id', $country)->exists()
            || ($state !== null && ! DB::connection('landlord')->table('states')->where('id', $state)->where('country_id', $country)->exists())) {
            throw ValidationException::withMessages(['country_id' => ['Choose a valid country and state.']]);
        }

        return ['country_id' => $country, 'state_id' => $state, 'address_id' => null];
    }

    /**
     * @return array{item_id: int|null, product: Product, variant: ProductVariant|null, quantity: string, status: string, warehouse: Warehouse|null, price: PriceResult|null, line_subtotal: string, discount_amount: string, seller_funded_discount_amount: string, tax_rate_applied: string|null, tax_amount: string|null, tax_breakdown: array<string, string>|null, line_total: string|null}
     */
    private function priceLine(CartItem $item, string $currency): array
    {
        $product = $item->product;
        $variant = $item->variant;
        $quantity = (string) $item->quantity;
        $status = self::sellable($product, $variant) ? Quote::OK : Quote::UNAVAILABLE;
        $warehouse = null;

        if ($status === Quote::OK && $product->isPhysical()) {
            $warehouse = $this->inventory->selectFulfillmentWarehouse($product, $variant, $quantity);
            $status = $warehouse === null ? Quote::OUT_OF_STOCK : Quote::OK;
        }

        $price = $status === Quote::UNAVAILABLE ? null : $this->pricing->resolveUnitPrice($product, $variant, $warehouse, $currency);
        $zero = Money::normalize(0);

        return [
            'item_id' => $item->id,
            'product' => $product,
            'variant' => $variant,
            'quantity' => $quantity,
            'status' => $status,
            'warehouse' => $warehouse,
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

    /**
     * Visible product; a variable product needs an active variant of its
     * own, any other product none.
     */
    public static function sellable(Product $product, ?ProductVariant $variant): bool
    {
        if ($product->trashed() || ! $product->is_active || ! in_array($product->moderation_status, ['not_required', 'approved'], true)) {
            return false;
        }

        if ($product->product_type === Product::VARIABLE) {
            return $variant !== null && ! $variant->trashed() && $variant->is_active && $variant->product_id === $product->id;
        }

        return $variant === null;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, string|null>  $totals
     * @param  array<string, int|null>|null  $address
     */
    private function hash(string $currency, array $lines, array $totals, ?string $coupon, ?int $methodId, ?array $address): string
    {
        return hash('sha256', (string) json_encode([
            $currency,
            array_map(static fn (array $l): array => [$l['product']->id, $l['variant']?->id, $l['quantity'], $l['status'], $l['price']?->unitPrice, $l['discount_amount'], $l['tax_amount']], $lines),
            $totals,
            $coupon,
            $methodId,
            $address === null ? null : [$address['country_id'], $address['state_id']],
        ]));
    }
}
