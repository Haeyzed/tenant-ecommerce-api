<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Http\PurchasingPresenter;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierPaymentService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase orders (spec §49.7).
 */
final class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly SupplierPaymentService $payments,
        private readonly PurchasingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(PurchaseOrder::STATUSES)],
            'supplier_id' => ['sometimes', 'integer'],
            'warehouse_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:32'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->orders->listPurchaseOrders($filters)->through(fn (PurchaseOrder $o): array => $this->presenter->purchaseOrder($o)));
    }

    /**
     * Body: supplier_id, warehouse_id, items[{product_id, product_variant_id?, quantity, unit_cost?}], currency_code?, order_date?, expected_date?, notes?, custom_fields?
     * A missing unit_cost is the supplier's cost for the product, else its catalogue cost.
     */
    public function store(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('tenant.suppliers', 'id')->whereNull('deleted_at')],
            'warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'items' => ['required', 'array'],
        ]);

        $order = $this->orders->createPurchaseOrder(
            Supplier::query()->findOrFail($ids['supplier_id']),
            Warehouse::query()->findOrFail($ids['warehouse_id']),
            array_values((array) $ids['items']),
            $this->user($request),
            $request->except(['supplier_id', 'warehouse_id', 'items']),
        );

        return APIResponse::created($this->detail($order), 'Purchase order created');
    }

    /**
     * ?q= name or SKU; ?warehouse_id= limits on-hand stock to one warehouse.
     */
    public function lookupProducts(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:100'], 'warehouse_id' => ['sometimes', 'integer', Rule::exists('tenant.warehouses', 'id')]]);
        $warehouse = isset($validated['warehouse_id']) ? Warehouse::query()->find($validated['warehouse_id']) : null;

        return APIResponse::success($this->orders->lookupProducts($validated['q'], $warehouse)->values());
    }

    public function show(PurchaseOrder $order): JsonResponse
    {
        return APIResponse::success($this->detail($order));
    }

    /**
     * Draft only. Body: warehouse_id?, currency_code?, order_date?, expected_date?, notes?, items? (replaces the lines), custom_fields?
     */
    public function update(Request $request, PurchaseOrder $order): JsonResponse
    {
        return APIResponse::success($this->detail($this->orders->updatePurchaseOrder($order, $request->all())), 'Purchase order updated');
    }

    public function submit(PurchaseOrder $order): JsonResponse
    {
        $this->orders->submitPurchaseOrder($order);

        return APIResponse::success($this->detail($order), 'Purchase order submitted');
    }

    /**
     * Body: items[{purchase_order_item_id, quantity}]. 409 approval_pending
     * while an approval workflow is deciding the order.
     */
    public function receive(Request $request, PurchaseOrder $order): JsonResponse
    {
        $this->orders->receiveStock($order, array_values((array) $request->input('items', [])));

        return APIResponse::success($this->detail($order), 'Stock received');
    }

    public function cancel(PurchaseOrder $order): JsonResponse
    {
        $this->orders->cancelPurchaseOrder($order);

        return APIResponse::success($this->detail($order), 'Purchase order cancelled');
    }

    public function duplicate(Request $request, PurchaseOrder $order): JsonResponse
    {
        return APIResponse::created($this->detail($this->orders->duplicatePurchaseOrder($order, $this->user($request))), 'Purchase order duplicated');
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(PurchaseOrder $order): array
    {
        $order = $this->orders->getPurchaseOrder($order->refresh());

        return $this->presenter->purchaseOrder($order, true, in_array($order->status, [PurchaseOrder::DRAFT, PurchaseOrder::CANCELLED], true) ? null : $this->payments->getBalanceForPurchaseOrder($order));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
