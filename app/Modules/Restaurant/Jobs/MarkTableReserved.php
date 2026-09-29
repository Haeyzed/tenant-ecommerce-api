<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Jobs;

use App\Modules\Restaurant\Models\RestaurantReservation;
use App\Modules\Restaurant\Services\RestaurantReservationService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Holds a table 30 minutes before its reservation (spec §65.1, A-55).
 * Dispatched with a delay to that moment; it re-reads the reservation and
 * does nothing once it is no longer confirmed, its time has moved (the
 * move dispatched a new job) or the hold window has not opened yet.
 */
final class MarkTableReserved implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $reservationId,
        public readonly string $reservationTime,
    ) {
        $this->onQueue('tenant-default');
    }

    public function handle(): void
    {
        Tenant::query()->find($this->tenantId)?->run(function (): void {
            $reservation = RestaurantReservation::query()->find($this->reservationId);

            if ($reservation === null || $reservation->status !== RestaurantReservation::CONFIRMED
                || $reservation->reservation_time->getTimestamp() !== CarbonImmutable::parse($this->reservationTime)->getTimestamp()
                || now()->lt($reservation->reservation_time->copy()->subMinutes(RestaurantReservationService::HOLD_MINUTES))) {
                return;
            }

            app(RestaurantReservationService::class)->markTableReserved($reservation);
        });
    }
}
