<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\RestaurantPresenter;
use App\Modules\Restaurant\Models\RestaurantReservation;
use App\Modules\Restaurant\Services\RestaurantReservationService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reservations (spec §65.5).
 */
final class ReservationController extends Controller
{
    private const array FIELDS = ['restaurant_table_id', 'customer_id', 'customer_name', 'customer_phone', 'party_size', 'reservation_time', 'notes'];

    public function __construct(
        private readonly RestaurantReservationService $reservations,
        private readonly RestaurantPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(RestaurantReservation::STATUSES)],
            'table_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->reservations->listReservations($filters)->through(fn (RestaurantReservation $r): array => $this->presenter->reservation($r)));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->reservation($this->reservations->createReservation($request->only(self::FIELDS))), 'Reservation confirmed');
    }

    public function update(Request $request, RestaurantReservation $reservation): JsonResponse
    {
        return APIResponse::success($this->presenter->reservation($this->reservations->updateReservation($reservation, $request->only(self::FIELDS))), 'Reservation updated');
    }

    /**
     * Seats the party and opens the table order.
     */
    public function seat(Request $request, RestaurantReservation $reservation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $order = $this->reservations->seatReservation($reservation, $user);

        return APIResponse::success(['reservation' => $this->presenter->reservation($reservation->refresh()->load('table.floor')), 'order_id' => $order->id], 'Party seated');
    }

    public function cancel(RestaurantReservation $reservation): JsonResponse
    {
        return APIResponse::success($this->presenter->reservation($this->reservations->cancelReservation($reservation)), 'Reservation cancelled');
    }

    public function noShow(RestaurantReservation $reservation): JsonResponse
    {
        return APIResponse::success($this->presenter->reservation($this->reservations->markNoShow($reservation)), 'Marked as no-show');
    }
}
