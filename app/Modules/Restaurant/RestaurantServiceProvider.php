<?php

declare(strict_types=1);

namespace App\Modules\Restaurant;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\Restaurant\Models\RestaurantReservation;
use Illuminate\Support\ServiceProvider;

/**
 * Personal data (§26.4): a customer's reservations are exported, and on
 * erasure their upcoming ones are cancelled and the name and phone
 * snapshots cleared.
 */
final class RestaurantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomerPrivacyRegistry::class, static function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('restaurant_reservations', static function (Customer $customer): void {
                RestaurantReservation::query()->where('customer_id', $customer->id)->where('status', RestaurantReservation::CONFIRMED)
                    ->update(['status' => RestaurantReservation::CANCELLED, 'updated_at' => now()]);
                RestaurantReservation::query()->where('customer_id', $customer->id)
                    ->update(['customer_name' => 'Erased customer', 'customer_phone' => null, 'notes' => null, 'updated_at' => now()]);
            });
            $privacy->registerSection('restaurant_reservations', static fn (Customer $customer): iterable => RestaurantReservation::query()
                ->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (RestaurantReservation $r): array => [
                    'reservation_time' => $r->reservation_time->toIso8601String(),
                    'party_size' => $r->party_size,
                    'status' => $r->status,
                    'name' => $r->customer_name,
                    'phone' => $r->customer_phone,
                ]));
        });
    }
}
