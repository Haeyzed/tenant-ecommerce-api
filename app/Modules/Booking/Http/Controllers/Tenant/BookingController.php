<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Http\BookingPresenter;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Storefront and account bookings (spec §66.3). A guest books with contact
 * details (and pays with their X-Guest-Token when prepayment is on);
 * customers see and cancel only their own.
 */
final class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly BookingPresenter $presenter,
    ) {}

    /**
     * Body: product_id, start_datetime, booking_staff_id, guest_name?, guest_email?, guest_phone?, notes?
     */
    public function store(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'product_id' => ['required', 'integer'],
            'booking_staff_id' => ['required', 'integer'],
            'start_datetime' => ['required', 'date'],
        ]);
        $customer = $request->user('customer');
        $product = Product::query()->visible()->whereKey($ids['product_id'])->first() ?? throw new NotFoundHttpException('Not found.');
        $staff = BookingStaff::query()->where('is_active', true)->find($ids['booking_staff_id']) ?? throw new NotFoundHttpException('Not found.');

        $booking = $this->bookings->createBooking($product, $staff, (string) $ids['start_datetime'], [
            ...$request->only(['guest_name', 'guest_email', 'guest_phone', 'notes']),
            'customer' => $customer instanceof Customer ? $customer : null,
            'guest_token' => ResolveGuestToken::from($request),
        ]);

        return APIResponse::created($this->presenter->booking($booking, false), $booking->status === Booking::PENDING_PAYMENT ? 'Booking held: pay the order to confirm it' : 'Booking confirmed');
    }

    public function index(Request $request): JsonResponse
    {
        return APIResponse::success($this->bookings->listForCustomer($this->customer($request))->map(fn (Booking $b): array => $this->presenter->booking($b, false))->values()->all());
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $customer = $this->customer($request);

        if ($booking->customer_id !== $customer->id) {
            throw new NotFoundHttpException('Not found.');
        }

        return APIResponse::success($this->presenter->booking($this->bookings->cancelBooking($booking, $customer), false), 'Booking cancelled');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
