<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingAvailability;
use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Booking\Models\BookingTimeOff;
use App\Modules\Booking\Services\BookingAvailabilityService;

/**
 * Booking responses (spec §66.3). Times are shown in the tenant timezone
 * with their offset. Guest contact details are for staff only.
 */
final readonly class BookingPresenter
{
    public function __construct(private BookingAvailabilityService $availability) {}

    /**
     * @return array<string, mixed>
     */
    public function staff(BookingStaff $staff): array
    {
        return [
            'id' => $staff->id,
            'user' => $staff->relationLoaded('user') ? ['id' => $staff->user->id, 'name' => $staff->user->name, 'email' => $staff->user->email] : ['id' => $staff->user_id],
            'is_active' => $staff->is_active,
            'availability' => $staff->relationLoaded('availability') ? $staff->availability->map(static fn (BookingAvailability $a): array => [
                'day_of_week' => $a->day_of_week, 'start_time' => substr($a->start_time, 0, 5), 'end_time' => substr($a->end_time, 0, 5),
            ])->values()->all() : [],
            'products' => $staff->relationLoaded('products') ? $staff->products->map(static fn ($p): array => ['id' => $p->id, 'name' => $p->name])->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function timeOff(BookingTimeOff $timeOff): array
    {
        $zone = $this->availability->timezone();

        return [
            'id' => $timeOff->id,
            'booking_staff_id' => $timeOff->booking_staff_id,
            'start_datetime' => $timeOff->start_datetime->copy()->setTimezone($zone)->toIso8601String(),
            'end_datetime' => $timeOff->end_datetime->copy()->setTimezone($zone)->toIso8601String(),
            'reason' => $timeOff->reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function booking(Booking $booking, bool $admin): array
    {
        $zone = $this->availability->timezone();
        $order = $booking->relationLoaded('order') ? $booking->order : null;

        return [
            'id' => $booking->id,
            'product' => $booking->relationLoaded('product') ? ['id' => $booking->product->id, 'name' => $booking->product->name, 'slug' => $booking->product->slug] : ['id' => $booking->product_id],
            'staff' => $booking->relationLoaded('staff') ? ['id' => $booking->staff->id, 'name' => $booking->staff->user?->name] : ['id' => $booking->booking_staff_id],
            'start_datetime' => $booking->start_datetime->copy()->setTimezone($zone)->toIso8601String(),
            'end_datetime' => $booking->end_datetime->copy()->setTimezone($zone)->toIso8601String(),
            'status' => $booking->status,
            'order' => $booking->order_id === null ? null : ($order === null ? ['id' => $booking->order_id] : [
                'id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status, 'payment_status' => $order->payment_status,
                'total' => (string) $order->total, 'currency_code' => $order->currency_code,
            ]),
            'notes' => $booking->notes,
            ...($admin ? [
                'customer' => $booking->customer_id === null ? null : ['id' => $booking->customer_id, 'name' => $booking->relationLoaded('customer') ? $booking->customer?->name : null],
                'guest_name' => $booking->guest_name,
                'guest_email' => $booking->guest_email,
                'guest_phone' => $booking->guest_phone,
            ] : []),
            'created_at' => $booking->created_at?->toIso8601String(),
        ];
    }
}
