<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\InventoryPresenter;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Stock adjustments (spec §33.3). productLookup serves GET
 * warehouses/{warehouse}/product-lookup (warehouses.product-lookup).
 */
final class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentService $adjustments,
        private readonly WarehouseService $warehouses,
        private readonly InventoryPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([StockAdjustment::DRAFT, StockAdjustment::SUBMITTED])],
            'warehouse_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->adjustments->listAdjustments($filters, $this->viewer($request))
            ->through(fn (StockAdjustment $a): array => $this->presenter->adjustment($a, false)));
    }

    /**
     * Multipart when an attachment is sent.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'attachment' => ['sometimes', 'nullable', 'file'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
        $viewer = $this->viewer($request);
        $this->warehouses->assertVisible($warehouse, $viewer);

        $attachment = $request->file('attachment');
        $adjustment = $this->adjustments->createAdjustment($warehouse, $validated['notes'] ?? null, $attachment instanceof UploadedFile ? $attachment : null, $viewer);

        return APIResponse::created($this->presenter->adjustment($this->adjustments->getAdjustment($adjustment), true), 'Adjustment created');
    }

    public function show(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->adjustments->assertVisible($adjustment, $this->viewer($request));

        return APIResponse::success($this->presenter->adjustment($this->adjustments->getAdjustment($adjustment), true));
    }

    public function submit(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->adjustments->assertVisible($adjustment, $this->viewer($request));

        return APIResponse::success($this->presenter->adjustment($this->adjustments->submitAdjustment($adjustment), true), 'Adjustment submitted');
    }

    public function productLookup(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->warehouses->assertVisible($warehouse, $this->viewer($request));
        $search = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']])['search'];

        return APIResponse::success($this->adjustments->lookupProducts($warehouse, $search));
    }

    private function viewer(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
