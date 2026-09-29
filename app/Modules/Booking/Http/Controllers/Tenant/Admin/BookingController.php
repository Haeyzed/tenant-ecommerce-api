<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Http\BookingPresenter;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bookings as staff see them (spec §66.3). Staff bookings are confirmed at
 * once; billing is a separate step (invoice).
 */
final class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly BookingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'booking_staff_id' => ['sometimes', 'integer'],
            'customer_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(Booking::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->bookings->listBookings($filters)->through(fn (Booking $b): array => $this->presenter->booking($b, true)));
    }

    /**
     * Body: product_id, booking_staff_id, start_datetime, customer_id? | guest_name + guest_email, guest_phone?, notes?
     */
    public function store(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'product_id' => ['required', 'integer'],
            'booking_staff_id' => ['required', 'integer'],
            'start_datetime' => ['required', 'date'],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $customer = isset($ids['customer_id']) ? Customer::query()->findOrFail($ids['customer_id']) : null;

        $booking = $this->bookings->createBooking(Product::query()->findOrFail($ids['product_id']), BookingStaff::query()->findOrFail($ids['booking_staff_id']), (string) $ids['start_datetime'],
            [...$request->only(['guest_name', 'guest_email', 'guest_phone', 'notes']), 'customer' => $customer, 'by_staff' => true]);

        return APIResponse::created($this->presenter->booking($booking, true), 'Booking confirmed');
    }

    /**
     * Body: start_datetime
     */
    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        $start = $request->validate(['start_datetime' => ['required', 'date']])['start_datetime'];

        return APIResponse::success($this->presenter->booking($this->bookings->rescheduleBooking($booking, (string) $start), true), 'Booking moved');
    }

    public function cancel(Booking $booking): JsonResponse
    {
        return APIResponse::success($this->presenter->booking($this->bookings->cancelBooking($booking), true), 'Booking cancelled');
    }

    public function complete(Booking $booking): JsonResponse
    {
        return APIResponse::success($this->presenter->booking($this->bookings->markCompleted($booking), true), 'Booking completed');
    }

    public function noShow(Booking $booking): JsonResponse
    {
        return APIResponse::success($this->presenter->booking($this->bookings->markNoShow($booking), true), 'Marked as no-show');
    }

    public function invoice(Booking $booking): JsonResponse
    {
        $order = $this->bookings->createOrderForBooking($booking);

        return APIResponse::created(['booking' => $this->presenter->booking($booking->refresh()->load(['product', 'staff.user', 'order']), true), 'order_id' => $order->id], 'Order created for the booking');
    }
}
