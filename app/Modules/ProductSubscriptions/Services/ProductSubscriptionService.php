<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Services;

use App\Modules\Cart\Services\PricingService;
use App\Modules\Cart\Support\PriceResult;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\ProductSubscriptions\Models\CustomerSubscription;
use App\Modules\ProductSubscriptions\Models\CustomerSubscriptionOrder;
use App\Modules\ProductSubscriptions\Models\ProductSubscriptionPlan;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Services\ShippingService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Customers subscribing to a product (spec §55): "coffee every 4 weeks".
 * Every delivery is an ordinary order: the first one is paid through the
 * normal payment route and saves the payment method; renewals are created
 * by the daily job and charged with it. The subscription discount replaces
 * promotions and flash sales.
 */
final readonly class ProductSubscriptionService
{
    public function __construct(
        private TenantSettingsService $settings,
        private OrderService $orders,
        private InventoryService $inventory,
        private PricingService $pricing,
        private CurrencyService $currencies,
        private ShippingService $shipping,
        private TaxService $tax,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * An order that starts a subscription still waiting for its first
     * payment: it is paid with save_authorization (§40.2).
     */
    public static function isFirstOrder(int $orderId): bool
    {
        return CustomerSubscriptionOrder::query()->where('order_id', $orderId)->where('is_renewal', false)
            ->whereHas('subscription', static fn ($q) => $q->where('status', CustomerSubscription::PENDING_PAYMENT))->exists();
    }

    /**
     * §55.2 step 1: the subscription in pending_payment and its first
     * order, which expires unpaid like any checkout order.
     *
     * @param  array<string, mixed>  $data  product_variant_id?, quantity?, address_id, shipping_method_id?, currency_code?
     * @return array{subscription: CustomerSubscription, order: Order}
     */
    public function createSubscription(Customer $customer, Product $product, ProductSubscriptionPlan $plan, array $data): array
    {
        $validated = Validator::make($data, [
            'product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'address_id' => ['required', 'integer'],
            'shipping_method_id' => ['sometimes', 'nullable', 'integer'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
        ])->validate();

        if (! $product->is_subscribable) {
            throw ApiException::unprocessable('product_not_subscribable', 'This product cannot be bought on subscription.');
        }

        if ($plan->product_id !== $product->id || ! $plan->is_active) {
            throw ApiException::unprocessable('subscription_plan_unavailable', 'This delivery schedule is not offered for the product.');
        }

        $variant = isset($validated['product_variant_id']) ? ProductVariant::query()->where('product_id', $product->id)->find((int) $validated['product_variant_id']) : null;

        if (! CheckoutService::sellable($product, $variant)) {
            throw ApiException::unprocessable($product->product_type === Product::VARIABLE && $variant === null ? 'variant_required' : 'item_unavailable',
                'Choose an available option of this product.');
        }

        $address = $this->ownAddress($customer, (int) $validated['address_id']);
        $methodId = $this->shippingMethodFor($product, $address, isset($validated['shipping_method_id']) ? (int) $validated['shipping_method_id'] : null);
        $requested = strtoupper((string) ($validated['currency_code'] ?? $this->currencies->baseCurrency()));
        $currency = $this->currencies->offered($requested) ? $requested : $this->currencies->baseCurrency();

        return DB::connection('tenant')->transaction(function () use ($customer, $product, $plan, $variant, $validated, $address, $methodId, $currency): array {
            $subscription = new CustomerSubscription;
            $subscription->forceFill([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_subscription_plan_id' => $plan->id,
                'quantity' => Quantity::normalize((string) ($validated['quantity'] ?? '1')),
                'address_id' => $address->id,
                'shipping_method_id' => $methodId,
                'currency_code' => $currency,
                'status' => CustomerSubscription::PENDING_PAYMENT,
            ])->save();

            $order = $this->createSubscriptionOrder($subscription, null);

            return ['subscription' => $subscription, 'order' => $order];
        });
    }

    /**
     * §55.2 step 2, in the payment's transition: the first payment
     * activates the subscription when the gateway returned a reusable
     * authorization; a paid renewal moves the schedule on one period.
     */
    public function handlePaymentSucceeded(OrderPayment $payment, ?string $authorizationToken): void
    {
        $link = CustomerSubscriptionOrder::query()->where('order_id', $payment->order_id)->first();

        if ($link === null) {
            return;
        }

        /** @var CustomerSubscription $subscription */
        $subscription = CustomerSubscription::query()->with('plan')->lockForUpdate()->findOrFail($link->customer_subscription_id);
        $token = $authorizationToken === null || $authorizationToken === '' ? null : $authorizationToken;

        if (! $link->is_renewal) {
            if ($subscription->status !== CustomerSubscription::PENDING_PAYMENT) {
                return;
            }

            if ($token === null) {
                // The order is paid and ships; the subscription cannot renew.
                $this->notifyCustomer('product_subscription.payment_method_not_saved', $subscription);

                return;
            }

            $subscription->forceFill([
                'status' => CustomerSubscription::ACTIVE,
                'payment_provider' => $payment->provider,
                'payment_method_token' => $token,
                'next_billing_date' => $subscription->plan->advance(CarbonImmutable::today())->toDateString(),
                'failed_renewal_count' => 0,
            ])->save();

            return;
        }

        if (! in_array($subscription->status, [CustomerSubscription::ACTIVE, CustomerSubscription::PAYMENT_FAILED], true)) {
            return;
        }

        // The next date keeps the schedule, unless renewals were held up for longer than a period.
        $next = $subscription->plan->advance($link->billing_date ?? CarbonImmutable::today());
        $next = $next->greaterThan(CarbonImmutable::today()) ? $next : $subscription->plan->advance(CarbonImmutable::today());

        $subscription->forceFill([
            'status' => CustomerSubscription::ACTIVE,
            'next_billing_date' => $next->toDateString(),
            'failed_renewal_count' => 0,
            'last_renewal_error' => null,
            ...($token === null ? [] : ['payment_method_token' => $token, 'payment_provider' => $payment->provider]),
        ])->save();
    }

    /**
     * §55.2 step 3, on failure: the subscription is payment_failed and is
     * retried on the next run, until product_subscription_max_failed_renewals
     * cancels it. The unpaid renewal order is cancelled after commit so its
     * stock goes back; the retry makes a fresh order. A failed first payment
     * is left to the customer, who pays the order again.
     *
     * @return bool true when a renewal answered the failure
     */
    public function handlePaymentFailed(OrderPayment $payment): bool
    {
        $link = CustomerSubscriptionOrder::query()->where('order_id', $payment->order_id)->where('is_renewal', true)->first();

        if ($link === null) {
            return false;
        }

        /** @var CustomerSubscription $subscription */
        $subscription = CustomerSubscription::query()->lockForUpdate()->findOrFail($link->customer_subscription_id);
        $orderId = $link->order_id;
        DB::connection('tenant')->afterCommit(fn () => $this->cancelUnpaidOrder($orderId, 'subscription_renewal_failed'));

        if (! in_array($subscription->status, [CustomerSubscription::ACTIVE, CustomerSubscription::PAYMENT_FAILED], true)) {
            return true;
        }

        $count = $subscription->failed_renewal_count + 1;
        $cancel = $count >= (int) $this->settings->get('product_subscription_max_failed_renewals', 3);

        $subscription->forceFill([
            'status' => $cancel ? CustomerSubscription::CANCELLED : CustomerSubscription::PAYMENT_FAILED,
            'failed_renewal_count' => $count,
            'last_renewal_error' => 'payment_failed: '.(((array) $payment->meta)['failure_reason'] ?? 'declined'),
            ...($cancel ? ['cancelled_at' => now(), 'payment_method_token' => null] : []),
        ])->save();

        $this->notifyCustomer('product_subscription.payment_failed', $subscription);

        if ($cancel) {
            $this->notifyCustomer('product_subscription.cancelled', $subscription);
        }

        return true;
    }

    /**
     * The order-cancellation hook: a first order that is cancelled (by the
     * customer, staff or unpaid expiry) takes its pending subscription with it.
     */
    public function cancelForOrder(Order $order): void
    {
        $link = CustomerSubscriptionOrder::query()->where('order_id', $order->id)->where('is_renewal', false)->first();

        if ($link !== null) {
            CustomerSubscription::query()->whereKey($link->customer_subscription_id)->where('status', CustomerSubscription::PENDING_PAYMENT)
                ->update(['status' => CustomerSubscription::CANCELLED, 'cancelled_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Pausing skips renewals (§55.2 step 4).
     */
    public function pauseSubscription(CustomerSubscription $subscription): CustomerSubscription
    {
        return $this->transition($subscription, [CustomerSubscription::ACTIVE, CustomerSubscription::PAYMENT_FAILED], CustomerSubscription::PAUSED);
    }

    /**
     * Resuming renews one period from today, not from the paused date.
     */
    public function resumeSubscription(CustomerSubscription $subscription): CustomerSubscription
    {
        return DB::connection('tenant')->transaction(function () use ($subscription): CustomerSubscription {
            /** @var CustomerSubscription $locked */
            $locked = CustomerSubscription::query()->with('plan')->lockForUpdate()->findOrFail($subscription->id);

            if ($locked->status !== CustomerSubscription::PAUSED) {
                throw ApiException::invalidTransition($locked->status, CustomerSubscription::ACTIVE);
            }

            $locked->forceFill([
                'status' => $locked->failed_renewal_count > 0 ? CustomerSubscription::PAYMENT_FAILED : CustomerSubscription::ACTIVE,
                'next_billing_date' => $locked->plan->advance(CarbonImmutable::today())->toDateString(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Stops every future renewal and forgets the payment method. A
     * subscription still waiting for its first payment cancels that order.
     * $notify: staff cancelled it, so the customer is told (§11.5).
     */
    public function cancelSubscription(CustomerSubscription $subscription, bool $notify = false): CustomerSubscription
    {
        $firstOrderId = null;

        $cancelled = DB::connection('tenant')->transaction(function () use ($subscription, &$firstOrderId): CustomerSubscription {
            /** @var CustomerSubscription $locked */
            $locked = CustomerSubscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($locked->status === CustomerSubscription::CANCELLED) {
                throw ApiException::invalidTransition($locked->status, CustomerSubscription::CANCELLED);
            }

            if ($locked->status === CustomerSubscription::PENDING_PAYMENT) {
                $firstOrderId = CustomerSubscriptionOrder::query()->where('customer_subscription_id', $locked->id)->where('is_renewal', false)->value('order_id');
            }

            $locked->forceFill(['status' => CustomerSubscription::CANCELLED, 'cancelled_at' => now(), 'payment_method_token' => null])->save();

            return $locked;
        });

        if ($firstOrderId !== null) {
            $this->cancelUnpaidOrder((int) $firstOrderId, 'subscription_cancelled');
        }

        if ($notify) {
            $this->notifyCustomer('product_subscription.cancelled', $cancelled);
        }

        return $cancelled;
    }

    /**
     * Quantity, variant, delivery address or shipping method, from the next
     * renewal on.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateQuantityOrVariant(CustomerSubscription $subscription, array $data): CustomerSubscription
    {
        $validated = Validator::make($data, [
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'address_id' => ['sometimes', 'integer'],
            'shipping_method_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($subscription, $validated): CustomerSubscription {
            /** @var CustomerSubscription $locked */
            $locked = CustomerSubscription::query()->with(['product', 'customer'])->lockForUpdate()->findOrFail($subscription->id);

            if ($locked->status === CustomerSubscription::CANCELLED) {
                throw ApiException::unprocessable('subscription_cancelled', 'This subscription is cancelled.');
            }

            $changes = [];

            if (array_key_exists('quantity', $validated)) {
                $changes['quantity'] = Quantity::normalize((string) $validated['quantity']);
            }

            if (array_key_exists('product_variant_id', $validated)) {
                $variant = $validated['product_variant_id'] === null ? null
                    : ProductVariant::query()->where('product_id', $locked->product_id)->find((int) $validated['product_variant_id']);

                if (! CheckoutService::sellable($locked->product, $variant)) {
                    throw ApiException::unprocessable('item_unavailable', 'Choose an available option of this product.');
                }

                $changes['product_variant_id'] = $variant?->id;
            }

            $address = array_key_exists('address_id', $validated) ? $this->ownAddress($locked->customer, (int) $validated['address_id']) : $locked->address;

            if (array_key_exists('address_id', $validated) || array_key_exists('shipping_method_id', $validated)) {
                if ($address === null) {
                    throw ApiException::unprocessable('address_invalid', 'Choose one of your saved addresses.');
                }

                $changes['address_id'] = $address->id;
                $changes['shipping_method_id'] = $this->shippingMethodFor($locked->product, $address,
                    array_key_exists('shipping_method_id', $validated) ? ($validated['shipping_method_id'] === null ? null : (int) $validated['shipping_method_id']) : $locked->shipping_method_id);
            }

            $locked->forceFill($changes)->save();

            return $locked;
        });
    }

    /**
     * §55.2 step 3: the renewal order at today's price less the
     * subscription discount, charged through the stored authorization
     * outside any transaction. The outcome arrives through the payment's
     * transition (handlePaymentSucceeded / handlePaymentFailed).
     */
    public function processRenewal(CustomerSubscription $subscription): Order
    {
        $order = DB::connection('tenant')->transaction(function () use ($subscription): Order {
            /** @var CustomerSubscription $locked */
            $locked = CustomerSubscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if (! in_array($locked->status, [CustomerSubscription::ACTIVE, CustomerSubscription::PAYMENT_FAILED], true)) {
                throw ApiException::unprocessable('subscription_not_renewable', 'This subscription is '.$locked->status.'.');
            }

            if ($locked->next_billing_date === null || $locked->next_billing_date->greaterThan(CarbonImmutable::today())) {
                throw ApiException::unprocessable('renewal_not_due', 'This subscription is not due for renewal.');
            }

            if ($locked->payment_method_token === null || $locked->payment_provider === null) {
                throw ApiException::unprocessable('no_stored_authorization', 'This subscription has no saved payment method.');
            }

            // One renewal at a time: an earlier charge may still be settling.
            $open = CustomerSubscriptionOrder::query()->where('customer_subscription_id', $locked->id)->where('is_renewal', true)
                ->whereHas('order', static fn ($q) => $q->where('status', '!=', Order::CANCELLED)->whereIn('payment_status', ['unpaid', 'failed']))
                ->exists();

            if ($open) {
                throw ApiException::conflict('renewal_in_progress', 'The previous renewal of this subscription is still being paid.');
            }

            return $this->createSubscriptionOrder($locked, $locked->next_billing_date->toDateString());
        });

        $subscription->refresh();

        if (! Money::isPositive((string) $order->total)) {
            // Nothing to charge: the order is paid, and its payment moves the schedule on.
            $this->orders->recalculatePaymentStatus($order);
            $this->advanceFreeRenewal($subscription, $order);

            return $order->refresh();
        }

        app(OrderPaymentService::class)->chargeStoredAuthorization($order, (string) $subscription->payment_provider,
            (string) $subscription->payment_method_token, (string) $order->total);

        return $order->refresh();
    }

    /**
     * ProcessProductSubscriptionRenewals (§55.2 step 3): each due
     * subscription on its own, so one failure never blocks the batch. A
     * renewal that cannot be made (the product is out of stock, the
     * address or shipping method is gone, the monthly order limit is
     * reached) keeps its date, records why for staff, and is tried again
     * on the next run; it is not a payment failure.
     *
     * @return int the renewals charged
     */
    public function processDueRenewals(): int
    {
        $renewed = 0;

        CustomerSubscription::query()->whereIn('status', [CustomerSubscription::ACTIVE, CustomerSubscription::PAYMENT_FAILED])
            ->whereNotNull('payment_method_token')->whereDate('next_billing_date', '<=', CarbonImmutable::today())
            ->orderBy('id')->pluck('id')
            ->each(function (int $id) use (&$renewed): void {
                $subscription = CustomerSubscription::query()->find($id);

                if ($subscription === null) {
                    return;
                }

                try {
                    $this->processRenewal($subscription);
                    $renewed++;
                } catch (ApiException $e) {
                    if (! in_array($e->errorCode, ['renewal_in_progress', 'renewal_not_due', 'subscription_not_renewable'], true)) {
                        $this->recordRenewalError($id, $e->errorCode.': '.$e->getMessage());
                    }
                } catch (Throwable $e) {
                    report($e);
                    $this->recordRenewalError($id, 'renewal_error: the renewal could not be created.');
                }
            });

        return $renewed;
    }

    /**
     * The personal-data eraser (§26.4): an anonymised customer's
     * subscriptions stop and their saved payment methods are deleted.
     */
    public function eraseForCustomer(Customer $customer): void
    {
        CustomerSubscription::query()->where('customer_id', $customer->id)->where('status', '!=', CustomerSubscription::CANCELLED)
            ->update(['status' => CustomerSubscription::CANCELLED, 'cancelled_at' => now(), 'updated_at' => now()]);
        CustomerSubscription::query()->where('customer_id', $customer->id)
            ->update(['payment_method_token' => null, 'address_id' => null]);
    }

    public function getSubscription(CustomerSubscription $subscription): CustomerSubscription
    {
        return $subscription->load(['product:id,name,slug,sku,product_type,subscription_discount_percent', 'variant:id,sku', 'plan',
            'customer:id,name,email', 'address', 'shippingMethod:id,name', 'subscriptionOrders.order:id,order_number,status,payment_status,total,currency_code,placed_at']);
    }

    /**
     * @return Collection<int, CustomerSubscription>
     */
    public function listCustomerSubscriptions(Customer $customer): Collection
    {
        return CustomerSubscription::query()->with(['product:id,name,slug,sku,product_type,subscription_discount_percent', 'variant:id,sku', 'plan'])
            ->where('customer_id', $customer->id)->orderByDesc('id')->get();
    }

    /**
     * @param  array{status?: string, customer_id?: int, product_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, CustomerSubscription>
     */
    public function listSubscriptions(array $filters = []): LengthAwarePaginator
    {
        return CustomerSubscription::query()->with(['product:id,name,slug,sku,product_type,subscription_discount_percent', 'variant:id,sku', 'plan', 'customer:id,name,email'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['customer_id']), static fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['product_id']), static fn ($q) => $q->where('product_id', $filters['product_id']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Builds the order of one delivery and links it. Inside the caller's
     * transaction.
     */
    private function createSubscriptionOrder(CustomerSubscription $subscription, ?string $billingDate): Order
    {
        $subscription->loadMissing(['product', 'variant', 'customer', 'address', 'shippingMethod']);
        $product = $subscription->product;
        $variant = $subscription->variant;
        $quantity = Quantity::normalize((string) $subscription->quantity);

        if (! CheckoutService::sellable($product, $variant) || ! $product->is_subscribable) {
            throw ApiException::unprocessable('item_unavailable', "{$product->name} can no longer be sold on subscription.");
        }

        $address = $subscription->address ?? throw ApiException::unprocessable('address_missing', 'The subscription has no delivery address.');
        $currency = $this->currencies->offered($subscription->currency_code) ? $subscription->currency_code : $this->currencies->baseCurrency();
        $rate = (string) ($this->currencies->rateFor($currency) ?? '1');
        $warehouse = $product->isPhysical() ? $this->inventory->selectFulfillmentWarehouse($product, $variant, $quantity) : null;

        if ($product->isPhysical() && $warehouse === null) {
            throw ApiException::conflict('stock_conflict', "{$product->name} is not in stock for this quantity.");
        }

        // The regular price: a running flash sale is a promotion, which the subscription discount replaces.
        $price = $this->pricing->resolveUnitPrice($product, $variant, $warehouse, $currency, $rate);
        $unitPrice = $price->source === PriceResult::FLASH_SALE && $price->compareAtPrice !== null ? $price->compareAtPrice : $price->unitPrice;
        $lineSubtotal = Money::round(bcmul($unitPrice, $quantity, 10), $currency);
        $percent = (string) ($product->subscription_discount_percent ?? '0');
        $discount = Money::round(bcdiv(bcmul($lineSubtotal, $percent, 10), '100', 10), $currency);
        $taxAddress = ['country_id' => $address->country_id, 'state_id' => $address->state_id];

        $shippingAmount = Money::normalize(0);

        if ($product->isPhysical()) {
            $method = $this->shipping->getAvailableMethods($taxAddress)->firstWhere('id', $subscription->shipping_method_id)
                ?? throw ApiException::unprocessable('shipping_method_unavailable', 'The subscription\'s shipping method is not available for its address.');
            // Method costs are base-currency amounts (A-17).
            $shippingAmount = $this->currencies->fromBase($this->shipping->calculateShippingCost($method, [$product]), $rate, $currency);
        }

        $net = Money::sub($lineSubtotal, $discount);
        $taxed = $this->tax->calculateForLines([['amount' => $net, 'tax_class' => (string) ($product->tax_class ?: 'standard'), 'origin' => $warehouse]],
            $taxAddress, null, $shippingAmount, $currency);
        $inclusive = (bool) $this->settings->get('prices_include_tax', false);
        $total = Money::add($net, $shippingAmount);
        $total = $inclusive ? $total : Money::add($total, $taxed['total']);
        $snapshot = [
            'name' => $address->recipient_name, 'phone' => $address->phone, 'line1' => $address->address_line_1, 'line2' => $address->address_line_2,
            'city_id' => $address->city_id, 'state_id' => $address->state_id, 'country_id' => $address->country_id, 'postal_code' => $address->postal_code,
        ];

        $order = $this->orders->createOrder([
            'order_source' => 'online',
            'status' => Order::PENDING,
            'customer' => $subscription->customer,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'prices_include_tax' => $inclusive,
            'lines' => [[
                'product' => $product, 'variant' => $variant, 'warehouse' => $warehouse, 'quantity' => $quantity,
                'unit_price' => $unitPrice, 'price_source' => 'subscription', 'discount_amount' => $discount, 'line_subtotal' => $lineSubtotal,
                'tax_rate_applied' => $taxed['lines'][0]['tax_rate_applied'], 'tax_amount' => $taxed['lines'][0]['tax_amount'],
                'tax_breakdown' => $taxed['lines'][0]['tax_breakdown'],
                'line_total' => $inclusive ? $net : Money::add($net, $taxed['lines'][0]['tax_amount']),
            ]],
            'totals' => [
                'subtotal' => $lineSubtotal, 'discount_amount' => $discount, 'shipping_amount' => $shippingAmount,
                'shipping_tax_amount' => $taxed['shipping_tax_amount'], 'tax_amount' => $taxed['total'], 'total' => $total,
            ],
            'shipping_method_id' => $product->isPhysical() ? $subscription->shipping_method_id : null,
            'shipping_address' => $snapshot,
            'billing_address' => $snapshot,
            // The first order is paid by the customer and expires unpaid; a renewal is charged at once.
            'expires' => $billingDate === null,
            'customer_note' => $billingDate === null ? 'Subscription start' : 'Subscription renewal of '.$billingDate,
        ]);

        $link = new CustomerSubscriptionOrder;
        $link->forceFill([
            'customer_subscription_id' => $subscription->id,
            'order_id' => $order->id,
            'is_renewal' => $billingDate !== null,
            'billing_date' => $billingDate,
        ])->save();

        return $order;
    }

    private function advanceFreeRenewal(CustomerSubscription $subscription, Order $order): void
    {
        DB::connection('tenant')->transaction(function () use ($subscription, $order): void {
            /** @var CustomerSubscription $locked */
            $locked = CustomerSubscription::query()->with('plan')->lockForUpdate()->findOrFail($subscription->id);
            $billed = CustomerSubscriptionOrder::query()->where('order_id', $order->id)->value('billing_date');
            $next = $locked->plan->advance(CarbonImmutable::parse($billed ?? CarbonImmutable::today()));
            $next = $next->greaterThan(CarbonImmutable::today()) ? $next : $locked->plan->advance(CarbonImmutable::today());

            $locked->forceFill(['status' => CustomerSubscription::ACTIVE, 'next_billing_date' => $next->toDateString(), 'failed_renewal_count' => 0, 'last_renewal_error' => null])->save();
        });
    }

    private function cancelUnpaidOrder(int $orderId, string $reason): void
    {
        $order = Order::query()->find($orderId);

        if ($order !== null && $order->status === Order::PENDING && in_array($order->payment_status, ['unpaid', 'failed'], true)) {
            $this->orders->cancelOrder($order, $reason);
        }
    }

    private function recordRenewalError(int $id, string $error): void
    {
        CustomerSubscription::query()->whereKey($id)->update(['last_renewal_error' => mb_substr($error, 0, 255), 'updated_at' => now()]);
    }

    private function ownAddress(Customer $customer, int $addressId): Address
    {
        return Address::query()->where('customer_id', $customer->id)->find($addressId)
            ?? throw ApiException::unprocessable('address_invalid', 'Choose one of your saved addresses.');
    }

    /**
     * A physical product needs a method offered at the address; other
     * products need none.
     */
    private function shippingMethodFor(Product $product, Address $address, ?int $methodId): ?int
    {
        if (! $product->isPhysical()) {
            return null;
        }

        if ($methodId === null) {
            throw ApiException::unprocessable('shipping_method_required', 'Choose a shipping method.');
        }

        if ($this->shipping->getAvailableMethods(['country_id' => $address->country_id, 'state_id' => $address->state_id])->firstWhere('id', $methodId) === null) {
            throw ApiException::unprocessable('shipping_method_unavailable', 'The shipping method is not available for this address.');
        }

        return $methodId;
    }

    /**
     * @param  list<string>  $from
     */
    private function transition(CustomerSubscription $subscription, array $from, string $to): CustomerSubscription
    {
        return DB::connection('tenant')->transaction(function () use ($subscription, $from, $to): CustomerSubscription {
            /** @var CustomerSubscription $locked */
            $locked = CustomerSubscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if (! in_array($locked->status, $from, true)) {
                throw ApiException::invalidTransition($locked->status, $to);
            }

            $locked->forceFill(['status' => $to])->save();

            return $locked;
        });
    }

    private function notifyCustomer(string $key, CustomerSubscription $subscription): void
    {
        $subscription->loadMissing(['customer', 'product']);
        $tenant = tenant();

        if ($subscription->customer === null || $subscription->customer->anonymized_at !== null) {
            return;
        }

        $this->notifications->dispatch($key, $subscription->customer, [
            'customer_name' => (string) $subscription->customer->name,
            'product_name' => $subscription->product->name,
            'store_name' => (string) $this->settings->get('store_name', ''),
            'account_url' => $tenant instanceof Tenant ? FrontendUrl::storefront($tenant, '/account/subscriptions/'.$subscription->id) : '',
        ]);
    }
}
