<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingAvailability;
use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Booking\Models\BookingTimeOff;
use App\Modules\Catalog\Models\Product;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Weekly hours, time off and open slots (spec §66.1, §66.2). Weekly hours
 * are wall-clock times in the tenant timezone; slots start every
 * duration_minutes from each window's start and drop out when they
 * overlap time off or a booking that holds its slot (A-57).
 */
final readonly class BookingAvailabilityService
{
    public function __construct(private TenantSettingsService $settings) {}

    /**
     * Replaces every weekly window.
     *
     * @param  list<array<string, mixed>>  $slots  day_of_week (0 = Sunday), start_time H:i, end_time H:i
     */
    public function setWeeklyAvailability(BookingStaff $staff, array $slots): BookingStaff
    {
        $validated = Validator::make(['slots' => $slots], [
            'slots' => ['present', 'array', 'max:70'],
            'slots.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i', 'after:slots.*.start_time'],
        ])->validate()['slots'];

        // No overlapping windows on one day.
        $byDay = [];

        foreach ($validated as $i => $slot) {
            foreach ($byDay[$slot['day_of_week']] ?? [] as [$start, $end]) {
                if ($slot['start_time'] < $end && $start < $slot['end_time']) {
                    throw ValidationException::withMessages(["slots.{$i}" => ['This window overlaps another on the same day.']]);
                }
            }

            $byDay[$slot['day_of_week']][] = [$slot['start_time'], $slot['end_time']];
        }

        DB::connection('tenant')->transaction(function () use ($staff, $validated): void {
            BookingAvailability::query()->where('booking_staff_id', $staff->id)->delete();

            foreach ($validated as $slot) {
                $row = new BookingAvailability;
                $row->forceFill([...$slot, 'booking_staff_id' => $staff->id])->save();
            }
        });

        return $staff->load(['user:id,name,email', 'availability', 'products:id,name']);
    }

    /**
     * @param  array<string, mixed>  $data  start_datetime, end_datetime (tenant local or with an offset), reason?
     */
    public function addTimeOff(BookingStaff $staff, array $data): BookingTimeOff
    {
        $validated = Validator::make($data, [
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        $row = new BookingTimeOff;
        $row->forceFill([
            'booking_staff_id' => $staff->id,
            'start_datetime' => $this->parse((string) $validated['start_datetime'])->utc(),
            'end_datetime' => $this->parse((string) $validated['end_datetime'])->utc(),
            'reason' => $validated['reason'] ?? null,
        ])->save();

        return $row;
    }

    public function removeTimeOff(BookingTimeOff $timeOff): void
    {
        $timeOff->delete();
    }

    /**
     * Open start times on one local date, soonest first, each with the
     * eligible staff free then (any eligible staff when $staff is null).
     *
     * @return list<array{start: string, end: string, booking_staff_ids: list<int>}>
     */
    public function getAvailableSlots(Product $product, ?BookingStaff $staff, string $date, ?int $ignoreBookingId = null): array
    {
        $this->assertBookable($product);
        $zone = $this->timezone();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone) ?: throw ApiException::unprocessable('date_invalid', 'Use a date as YYYY-MM-DD.');
        $minutes = (int) $product->duration_minutes;

        $eligible = BookingStaff::query()->where('is_active', true)->whereHas('products', static fn ($q) => $q->whereKey($product->id))
            ->when($staff !== null, static fn ($q) => $q->whereKey($staff?->id))->with(['availability' => static fn ($q) => $q->where('day_of_week', $day->dayOfWeek)])->get();

        if ($eligible->isEmpty()) {
            return [];
        }

        $from = $day->utc();
        $to = $day->addDay()->utc();
        $busy = Booking::query()->whereIn('booking_staff_id', $eligible->modelKeys())->whereIn('status', Booking::HOLDING)
            ->when($ignoreBookingId !== null, static fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->where('start_datetime', '<', $to)->where('end_datetime', '>', $from)->get(['booking_staff_id', 'start_datetime', 'end_datetime'])
            ->map(static fn (Booking $b): array => [$b->booking_staff_id, $b->start_datetime->getTimestamp(), $b->end_datetime->getTimestamp()]);
        $off = BookingTimeOff::query()->whereIn('booking_staff_id', $eligible->modelKeys())
            ->where('start_datetime', '<', $to)->where('end_datetime', '>', $from)->get()
            ->map(static fn (BookingTimeOff $t): array => [$t->booking_staff_id, $t->start_datetime->getTimestamp(), $t->end_datetime->getTimestamp()]);
        $blocked = $busy->concat($off);
        $now = now()->getTimestamp();
        $slots = [];

        foreach ($eligible as $member) {
            foreach ($member->availability as $window) {
                $start = $day->setTimeFromTimeString($window->start_time);
                $end = $day->setTimeFromTimeString($window->end_time);

                for ($slot = $start; $slot->addMinutes($minutes)->lte($end); $slot = $slot->addMinutes($minutes)) {
                    $a = $slot->getTimestamp();
                    $b = $slot->addMinutes($minutes)->getTimestamp();

                    if ($a <= $now || $blocked->contains(static fn (array $x): bool => $x[0] === $member->id && $a < $x[2] && $x[1] < $b)) {
                        continue;
                    }

                    $key = $slot->format('Y-m-d\TH:i');
                    $slots[$key] ??= ['start' => $slot->toIso8601String(), 'end' => $slot->addMinutes($minutes)->toIso8601String(), 'booking_staff_ids' => []];
                    $slots[$key]['booking_staff_ids'][] = $member->id;
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    public function assertBookable(Product $product): void
    {
        if (! $product->is_bookable || $product->product_type !== Product::SERVICE || $product->duration_minutes === null || ! $product->is_active || $product->trashed()) {
            throw ApiException::unprocessable('product_not_bookable', 'This service cannot be booked.');
        }
    }

    public function timezone(): string
    {
        return (string) ($this->settings->get('timezone') ?: 'UTC');
    }

    /**
     * A date-time in the tenant timezone unless it carries an offset.
     */
    public function parse(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $this->timezone());
    }
}
