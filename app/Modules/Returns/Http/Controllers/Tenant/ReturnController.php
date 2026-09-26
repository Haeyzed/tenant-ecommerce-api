<?php

declare(strict_types=1);

namespace App\Modules\Returns\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Http\OrderAccess;
use App\Modules\Orders\Models\Order;
use App\Modules\Returns\Http\ReturnPresenter;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Returns\Services\ReturnReasonService;
use App\Modules\Returns\Services\ReturnService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The shopper's returns (spec §41.7): own orders, or guest orders by token.
 */
final class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly ReturnPresenter $presenter,
    ) {}

    public function indexForOrder(Request $request, Order $order, ReturnReasonService $reasons): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);

        return APIResponse::success([
            'returns' => $this->returns->getReturnsForOrder($order)->map(fn (OrderReturn $r): array => $this->presenter->return($r, false))->values(),
            'reasons' => $reasons->listReasons(true)->map(fn ($r): array => $this->presenter->reason($r))->values(),
        ]);
    }

    /**
     * Multipart: items[], reason_id, resolution_type, note, photos[].
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);
        $request->validate(['reason_id' => ['required', 'integer'], 'resolution_type' => ['required', 'string'], 'items' => ['required', 'array']]);

        $photos = array_values(array_filter((array) $request->file('photos', []), static fn ($f): bool => $f instanceof UploadedFile));
        $customer = $request->user();

        $return = $this->returns->requestReturn($order, $customer instanceof Customer ? $customer : null, (array) $request->input('items'),
            (int) $request->input('reason_id'), (string) $request->input('resolution_type'), $request->input('note'), $photos);

        return APIResponse::created($this->presenter->return($return->load(['items.orderItem', 'reason', 'order']), false), 'Return requested');
    }

    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        if (! $customer instanceof Customer) {
            throw new ApiException('unauthenticated', 'Sign in to see your returns.', 401);
        }

        return APIResponse::success($this->returns->getReturnsForCustomer($customer)->map(fn (OrderReturn $r): array => $this->presenter->return($r, false))->values());
    }

    public function show(Request $request, OrderReturn $return): JsonResponse
    {
        OrderAccess::assertCanView($request, $return->order);

        return APIResponse::success($this->presenter->return($return->load(['items.orderItem', 'reason', 'order']), false));
    }
}
