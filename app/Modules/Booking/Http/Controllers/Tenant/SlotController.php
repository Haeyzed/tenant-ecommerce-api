<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Booking\Services\BookingAvailabilityService;
use App\Modules\Catalog\Models\Product;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Open slots for a bookable service (spec §66.3), by id or slug.
 */
final class SlotController extends Controller
{
    public function __construct(private readonly BookingAvailabilityService $availability) {}

    /**
     * Query: date (YYYY-MM-DD, tenant local), booking_staff_id?
     */
    public function index(Request $request, string $product): JsonResponse
    {
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'booking_staff_id' => ['sometimes', 'integer']]);
        $model = (ctype_digit($product) ? Product::query()->visible()->whereKey((int) $product)->first() : null)
            ?? Product::query()->visible()->where('slug', $product)->first()
            ?? throw new NotFoundHttpException('Not found.');
        $staff = isset($validated['booking_staff_id'])
            ? BookingStaff::query()->where('is_active', true)->find($validated['booking_staff_id']) ?? throw new NotFoundHttpException('Not found.')
            : null;

        return APIResponse::success([
            'product_id' => $model->id,
            'date' => $validated['date'],
            'duration_minutes' => $model->duration_minutes,
            'slots' => $this->availability->getAvailableSlots($model, $staff, (string) $validated['date']),
        ]);
    }
}
