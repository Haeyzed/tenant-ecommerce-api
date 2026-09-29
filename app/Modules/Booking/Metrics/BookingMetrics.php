<?php

declare(strict_types=1);

namespace App\Modules\Booking\Metrics;

use App\Modules\Booking\Models\Booking;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The booking section (spec §44.3): confirmed appointments still ahead,
 * and appointments completed and missed in the range.
 */
final readonly class BookingMetrics
{
    public function booking(DateRange $range): SectionResult
    {
        $comparison = $range->comparison();
        $in = static fn (DateRange $r, string $status): int => DB::connection('tenant')->table('bookings')->where('status', $status)
            ->whereBetween('start_datetime', [$r->startUtc(), $r->endUtc()])->count();

        return new SectionResult(kpis: [
            KpiValue::count('upcoming', 'Upcoming', DB::connection('tenant')->table('bookings')->where('status', Booking::CONFIRMED)->where('start_datetime', '>=', now())->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('completed', 'Completed', $in($range, Booking::COMPLETED), $range, $comparison === null ? null : $in($comparison, Booking::COMPLETED)),
            KpiValue::count('no_shows', 'No-shows', $in($range, Booking::NO_SHOW), $range, $comparison === null ? null : $in($comparison, Booking::NO_SHOW), KpiValue::DOWN_IS_GOOD),
        ]);
    }
}
