<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLifecycle;
use Illuminate\Support\ServiceProvider;

/**
 * Bookings on the order lifecycle (§66.2): a prepaid booking is confirmed
 * with its order and cancelled when the order is cancelled or expires.
 * Custom fields (§23.1) and personal data (§26.4).
 */
final class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(OrderLifecycle::class, function (OrderLifecycle $lifecycle): void {
            $lifecycle->on(OrderLifecycle::CONFIRMED, 'booking', fn (Order $order) => $this->app->make(BookingService::class)->confirmForOrder($order));
            $lifecycle->on(OrderLifecycle::CANCELLED, 'booking', fn (Order $order) => $this->app->make(BookingService::class)->cancelForOrder($order));
        });

        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register('booking', Booking::class, 'bookings', 'booking');
        });

        $this->app->afterResolving(CustomerPrivacyRegistry::class, function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('bookings', fn (Customer $customer) => $this->app->make(BookingService::class)->eraseForCustomer($customer));
            $privacy->registerSection('bookings', static fn (Customer $customer): iterable => Booking::query()->with('product:id,name')
                ->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (Booking $b): array => [
                    'service' => $b->product->name,
                    'start' => $b->start_datetime->toIso8601String(),
                    'end' => $b->end_datetime->toIso8601String(),
                    'status' => $b->status,
                    'notes' => $b->notes,
                ]));
        });
    }
}
