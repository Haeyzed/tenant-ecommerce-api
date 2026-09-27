<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Http\PurchasingPresenter;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase returns (spec §49.5, §49.7).
 */
final class PurchaseReturnController extends Controller
{
    public function __construct(
        private readonly PurchaseReturnService $returns,
        private readonly PurchasingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(PurchaseReturn::STATUSES)],
            'supplier_id' => ['sometimes', 'integer'],
            'purchase_order_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->returns->listReturns($filters)->through(fn (PurchaseReturn $r): array => $this->presenter->purchaseReturn($r)));
    }

    /**
     * Body: purchase_order_id, purchase_return_reason_id, items[{purchase_order_item_id, quantity}], warehouse_id? (default: the order's), note?
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => ['required', 'integer', Rule::exists('tenant.purchase_orders', 'id')],
            'purchase_return_reason_id' => ['required', 'integer'],
            'warehouse_id' => ['sometimes', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'items' => ['required', 'array'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $order = PurchaseOrder::query()->findOrFail($validated['purchase_order_id']);
        /** @var User $by */
        $by = $request->user();

        $return = $this->returns->requestReturn($order, Warehouse::query()->findOrFail($validated['warehouse_id'] ?? $order->warehouse_id),
            array_values((array) $validated['items']), (int) $validated['purchase_return_reason_id'], $validated['note'] ?? null, $by);

        return APIResponse::created($this->detail($return), 'Purchase return requested');
    }

    public function show(PurchaseReturn $return): JsonResponse
    {
        return APIResponse::success($this->detail($return));
    }

    public function approve(PurchaseReturn $return): JsonResponse
    {
        $this->returns->approveReturn($return);

        return APIResponse::success($this->detail($return), 'Purchase return approved');
    }

    /**
     * Body: reason.
     */
    public function reject(Request $request, PurchaseReturn $return): JsonResponse
    {
        $this->returns->rejectReturn($return, (string) $request->input('reason', ''));

        return APIResponse::success($this->detail($return), 'Purchase return rejected');
    }

    public function shipBack(PurchaseReturn $return): JsonResponse
    {
        $this->returns->markShippedBack($return);

        return APIResponse::success($this->detail($return), 'Marked as shipped back');
    }

    /**
     * Body: resolution (refund | credit_note); for a refund: amount? (default
     * the returned value), payment_method?, account_id?, paid_at?, reference?, notes?
     */
    public function refund(Request $request, PurchaseReturn $return): JsonResponse
    {
        /** @var User $by */
        $by = $request->user();
        $this->returns->processSupplierRefund($return, (string) $request->input('resolution', ''), $by, $request->except('resolution'));

        return APIResponse::success($this->detail($return), 'Purchase return resolved');
    }

    public function close(PurchaseReturn $return): JsonResponse
    {
        $this->returns->closeReturn($return);

        return APIResponse::success($this->detail($return), 'Purchase return closed');
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(PurchaseReturn $return): array
    {
        return $this->presenter->purchaseReturn($this->returns->getReturn($return->refresh()), true);
    }
}
