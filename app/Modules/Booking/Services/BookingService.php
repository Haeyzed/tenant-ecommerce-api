<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Cart\Services\PricingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Bookings (spec §66.2). The staff member's row is locked and the slot
 * re-checked inside the transaction, so a slot is never sold twice. With
 * booking_requires_prepayment, a storefront booking waits for its online
 * order (pending_payment, holding the slot) and follows that order:
 * confirmed with it, cancelled when it is cancelled or expires.
 */
final readonly class BookingService
{
    public function __construct(
        private BookingAvailabilityService $availability,
        private OrderService $orders,
        private PricingService $pricing,
        private TaxService $tax,
        private CurrencyService $currencies,
        private TenantSettingsService $settings,
        private PlatformSettingsService $platformSettings,
    ) {}

    /**
     * @param  array<string, mixed>  $data  customer (Customer|null), guest_name?, guest_email?, guest_phone?, notes?, by_staff (bool), guest_token?
     */
    public function createBooking(Product $product, BookingStaff $staff, string $start, array $data): Booking
    {
        $validated = Validator::make($data, [
            'guest_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'guest_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'guest_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        /** @var Customer|null $customer */
        $customer = $data['customer'] ?? null;
        $byStaff = (bool) ($data['by_staff'] ?? false);
        $this->availability->assertBookable($product);

        if ($customer === null) {
            if (! $byStaff && ! ((bool) $this->settings->get('guest_checkout_enabled', true) && (bool) $this->platformSettings->get('guest_checkout_allowed_platform_wide', true))) {
                throw ApiException::forbidden('guest_checkout_disabled', 'Sign in to book.');
            }

            if (($validated['guest_name'] ?? null) === null || ($validated['guest_email'] ?? null) === null) {
                throw ApiException::unprocessable('guest_details_required', 'Enter a name and e-mail address for the booking.');
            }
        }

        // Staff bookings are always confirmed (§66.2).
        $prepay = ! $byStaff && (bool) $this->settings->get('booking_requires_prepayment', false);
        $guestToken = $data['guest_token'] ?? null;

        if ($prepay && $customer === null && $guestToken === null) {
            throw ApiException::unprocessable('guest_token_required', 'Send the X-Guest-Token header to pay for a guest booking.');
        }

        $at = $this->availability->parse($start);

        return DB::connection('tenant')->transaction(function () use ($product, $staff, $at, $customer, $validated, $prepay, $guestToken): Booking {
            $this->lockSlot($product, $staff, $at, null);

            $booking = new Booking;
            $booking->forceFill([
                'product_id' => $product->id,
                'booking_staff_id' => $staff->id,
                'customer_id' => $customer?->id,
                'guest_name' => $customer === null ? $validated['guest_name'] : null,
                'guest_email' => $customer === null ? strtolower((string) $validated['guest_email']) : null,
                'guest_phone' => $customer === null ? ($validated['guest_phone'] ?? null) : null,
                'start_datetime' => $at->utc(),
                'end_datetime' => $at->addMinutes((int) $product->duration_minutes)->utc(),
                'status' => $prepay ? Booking::PENDING_PAYMENT : Booking::CONFIRMED,
                'notes' => $validated['notes'] ?? null,
            ])->save();

            if ($prepay) {
                // An online order: the unpaid-order expiry applies (§39.5).
                $order = $this->billingOrder($booking, $product, 'online', $customer, $guestToken, true);
                $booking->forceFill(['order_id' => $order->id])->save();
            }

            return $booking->load(['product:id,name,slug,duration_minutes', 'staff.user:id,name', 'order:id,order_number,status,payment_status,total,currency_code']);
        });
    }

    public function rescheduleBooking(Booking $booking, string $newStart): Booking
    {
        return DB::connection('tenant')->transaction(function () use ($booking, $newStart): Booking {
            $locked = $this->lockBooking($booking, Booking::HOLDING, 'rescheduled');
            $product = $locked->product;
            $at = $this->availability->parse($newStart);

            $this->lockSlot($product, $locked->staff, $at, $locked->id);
            $locked->forceFill(['start_datetime' => $at->utc(), 'end_datetime' => $at->addMinutes((int) $product->duration_minutes)->utc()])->save();

            return $locked->load(['product:id,name,slug,duration_minutes', 'staff.user:id,name']);
        });
    }

    /**
     * A customer cancels their own confirmed booking before it starts
     * (§66.2); staff cancel any booking holding a slot. An unpaid order
     * for it is cancelled with it.
     */
    public function cancelBooking(Booking $booking, ?Customer $customer = null): Booking
    {
        $booking = DB::connection('tenant')->transaction(function () use ($booking, $customer): Booking {
            $locked = $this->lockBooking($booking, $customer === null ? Booking::HOLDING : [Booking::CONFIRMED], Booking::CANCELLED);

            if ($customer !== null && $locked->start_datetime->isPast()) {
                throw ApiException::unprocessable('booking_started', 'This booking has already started. Contact the store.');
            }

            $locked->forceFill(['status' => Booking::CANCELLED])->save();

            return $locked;
        });

        $order = $booking->order_id === null ? null : Order::query()->find($booking->order_id);

        if ($order !== null && $order->status === Order::PENDING && $order->confirmed_at === null && $order->payment_status !== 'paid') {
            $this->orders->cancelOrder($order, 'Booking cancelled');
        }

        return $booking->load(['product:id,name,slug,duration_minutes', 'staff.user:id,name']);
    }

    public function markCompleted(Booking $booking): Booking
    {
        return $this->transition($booking, Booking::COMPLETED);
    }

    public function markNoShow(Booking $booking): Booking
    {
        if ($booking->start_datetime->isFuture()) {
            throw ApiException::unprocessable('booking_not_started', 'A booking can be marked as a no-show once it has started.');
        }

        return $this->transition($booking, Booking::NO_SHOW);
    }

    /**
     * Bills a booking made without prepayment (§66.2): an admin order with
     * one service line, paid through the normal order flow. Once per
     * booking.
     */
    public function createOrderForBooking(Booking $booking): Order
    {
        return DB::connection('tenant')->transaction(function () use ($booking): Order {
            $locked = $this->lockBooking($booking, [Booking::CONFIRMED, Booking::COMPLETED], 'invoiced');

            if ($locked->order_id !== null) {
                throw ApiException::conflict('booking_already_invoiced', 'This booking has an order already.', ['order_id' => $locked->order_id]);
            }

            $order = $this->billingOrder($locked, $locked->product, 'admin', $locked->customer, null, false);
            $locked->forceFill(['order_id' => $order->id])->save();
            $booking->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    /**
     * OrderLifecycle CONFIRMED: the prepaid booking is confirmed.
     */
    public function confirmForOrder(Order $order): void
    {
        Booking::query()->where('order_id', $order->id)->where('status', Booking::PENDING_PAYMENT)
            ->update(['status' => Booking::CONFIRMED, 'updated_at' => now()]);
    }

    /**
     * OrderLifecycle CANCELLED: an unpaid booking is cancelled and frees its
     * slot; a confirmed booking whose invoice was cancelled can be billed
     * again.
     */
    public function cancelForOrder(Order $order): void
    {
        Booking::query()->where('order_id', $order->id)->where('status', Booking::PENDING_PAYMENT)
            ->update(['status' => Booking::CANCELLED, 'updated_at' => now()]);
        Booking::query()->where('order_id', $order->id)->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->update(['order_id' => null, 'updated_at' => now()]);
    }

    /**
     * @param  array{booking_staff_id?: int, customer_id?: int, from?: string, to?: string, status?: string, per_page?: int}  $filters  from/to are local dates
     * @return LengthAwarePaginator<int, Booking>
     */
    public function listBookings(array $filters): LengthAwarePaginator
    {
        $zone = $this->availability->timezone();

        return Booking::query()->with(['product:id,name,slug,duration_minutes', 'staff.user:id,name', 'customer:id,name,email'])
            ->when(isset($filters['booking_staff_id']), static fn ($q) => $q->where('booking_staff_id', $filters['booking_staff_id']))
            ->when(isset($filters['customer_id']), static fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), static fn ($q) => $q->where('start_datetime', '>=', CarbonImmutable::parse((string) $filters['from'], $zone)->startOfDay()->utc()))
            ->when(isset($filters['to']), static fn ($q) => $q->where('start_datetime', '<', CarbonImmutable::parse((string) $filters['to'], $zone)->addDay()->startOfDay()->utc()))
            ->orderBy('start_datetime')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @return Collection<int, Booking>
     */
    public function listForCustomer(Customer $customer): Collection
    {
        return Booking::query()->with(['product:id,name,slug,duration_minutes', 'staff.user:id,name', 'order:id,order_number,status,payment_status,total,currency_code'])
            ->where('customer_id', $customer->id)->orderByDesc('start_datetime')->limit(200)->get();
    }

    /**
     * Personal data (§26.4): the customer's upcoming bookings are cancelled.
     */
    public function eraseForCustomer(Customer $customer): void
    {
        Booking::query()->where('customer_id', $customer->id)->whereIn('status', Booking::HOLDING)
            ->update(['status' => Booking::CANCELLED, 'updated_at' => now()]);
    }

    /**
     * Locks the staff member (serialising their bookings) and re-checks
     * that the start is an open slot for them.
     */
    private function lockSlot(Product $product, BookingStaff $staff, CarbonImmutable $at, ?int $ignoreBookingId): void
    {
        /** @var BookingStaff $locked */
        $locked = BookingStaff::query()->lockForUpdate()->findOrFail($staff->id);
        $local = $at->setTimezone($this->availability->timezone());
        $slots = $this->availability->getAvailableSlots($product, $locked, $local->toDateString(), $ignoreBookingId);
        $wanted = $local->getTimestamp();

        foreach ($slots as $slot) {
            if (CarbonImmutable::parse($slot['start'])->getTimestamp() === $wanted) {
                return;
            }
        }

        throw ApiException::conflict('slot_unavailable', 'This time is no longer available. Choose another slot.');
    }

    /**
     * @param  list<string>  $from
     */
    private function lockBooking(Booking $booking, array $from, string $to): Booking
    {
        /** @var Booking $locked */
        $locked = Booking::query()->with(['product', 'staff', 'customer'])->lockForUpdate()->findOrFail($booking->id);

        if (! in_array($locked->status, $from, true)) {
            throw ApiException::invalidTransition($locked->status, $to);
        }

        return $locked;
    }

    private function transition(Booking $booking, string $to): Booking
    {
        return DB::connection('tenant')->transaction(function () use ($booking, $to): Booking {
            $locked = $this->lockBooking($booking, [Booking::CONFIRMED], $to);
            $locked->forceFill(['status' => $to])->save();

            return $locked->load(['product:id,name,slug,duration_minutes', 'staff.user:id,name']);
        });
    }

    /**
     * One service line at the resolved base price, taxed at the store's
     * main location (a service ships nowhere; D-128).
     */
    private function billingOrder(Booking $booking, Product $product, string $source, ?Customer $customer, ?string $guestToken, bool $expires): Order
    {
        $currency = $this->currencies->baseCurrency();
        $price = $this->pricing->resolveUnitPrice($product, null, null, $currency, '1');
        $unit = $price->unitPrice;
        $inclusive = (bool) $this->settings->get('prices_include_tax', false);
        $store = Warehouse::query()->where('code', Warehouse::DEFAULT_CODE)->first() ?? Warehouse::query()->where('is_active', true)->orderBy('id')->first();
        $taxed = $store?->country_id === null ? null : $this->tax->calculateForLines([['amount' => $unit, 'tax_class' => (string) ($product->tax_class ?: 'standard'), 'origin' => $store]],
            ['country_id' => $store->country_id, 'state_id' => $store->state_id, 'address_id' => null], $store, '0', $currency);
        $lineTax = $taxed['lines'][0]['tax_amount'] ?? Money::normalize(0);
        $local = $booking->start_datetime->copy()->setTimezone($this->availability->timezone());

        return $this->orders->createOrder([
            'order_source' => $source,
            'status' => Order::PENDING,
            'customer' => $customer,
            'guest_token' => $customer === null ? $guestToken : null,
            'customer_name' => $customer?->name ?? $booking->guest_name,
            'customer_email' => $customer?->email ?? $booking->guest_email,
            'customer_phone' => $customer?->phone ?? $booking->guest_phone,
            'currency_code' => $currency,
            'exchange_rate' => '1',
            'prices_include_tax' => $inclusive,
            'lines' => [[
                'product' => $product, 'variant' => null, 'warehouse' => null, 'quantity' => '1',
                'unit_price' => $unit, 'price_source' => $price->source,
                'tax_rate_applied' => $taxed['lines'][0]['tax_rate_applied'] ?? '0', 'tax_amount' => $lineTax,
                'tax_breakdown' => $taxed['lines'][0]['tax_breakdown'] ?? null,
                'line_total' => $inclusive ? $unit : Money::add($unit, $lineTax),
            ]],
            'totals' => ['subtotal' => $unit, 'tax_amount' => $lineTax, 'total' => $inclusive ? $unit : Money::add($unit, $lineTax)],
            'expires' => $expires,
            'customer_note' => 'Booking #'.$booking->id.': '.$local->format('Y-m-d H:i'),
        ]);
    }
}
