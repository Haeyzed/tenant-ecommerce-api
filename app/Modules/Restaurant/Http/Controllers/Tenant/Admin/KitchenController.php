<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Restaurant\Services\KitchenDisplayService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Http\APIResponse;
use App\Shared\Metrics\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The kitchen display (spec §65.3), separate from the sales dashboard.
 */
final class KitchenController extends Controller
{
    public function __construct(
        private readonly KitchenDisplayService $kitchen,
        private readonly TenantSettingsService $settings,
    ) {}

    public function tickets(Request $request): JsonResponse
    {
        $filters = $request->validate(['floor_id' => ['sometimes', 'integer']]);

        return APIResponse::success($this->kitchen->getActiveTickets($filters));
    }

    /**
     * Body: status (preparing | ready | served)
     */
    public function updateItemStatus(Request $request, OrderItem $item): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'string']])['status'];
        $item = $this->kitchen->updateItemStatus($item, $status);

        return APIResponse::success(['id' => $item->id, 'kitchen_status' => $item->kitchen_status, 'kitchen_ready_at' => $item->kitchen_ready_at?->toIso8601String()], 'Dish updated');
    }

    /**
     * Query: range? (today by default), from?, to? (custom)
     */
    public function metrics(Request $request): JsonResponse
    {
        $input = $request->validate([
            'range' => ['sometimes', Rule::in(['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'custom'])],
            'from' => ['required_if:range,custom', 'date_format:Y-m-d'],
            'to' => ['required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $range = DateRange::fromInput([...$input, 'range' => $input['range'] ?? 'today', 'compare' => 'none'], (string) ($this->settings->get('timezone') ?: 'UTC'));

        return APIResponse::success($this->kitchen->getMetrics($range));
    }
}
