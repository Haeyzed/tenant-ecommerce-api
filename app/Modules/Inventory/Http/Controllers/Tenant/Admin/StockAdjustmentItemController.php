<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Http\InventoryPresenter;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockAdjustmentItem;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lines of a draft stock adjustment (spec §33.3). A line is looked up
 * within its adjustment.
 */
final class StockAdjustmentItemController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentService $adjustments,
        private readonly InventoryPresenter $presenter,
    ) {}

    public function store(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->visible($request, $adjustment);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')],
            'product_variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.product_variants', 'id')->whereNull('deleted_at')],
            'action' => ['required', 'string'],
            'quantity' => ['required'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $item = $this->adjustments->addItem(
            $adjustment,
            Product::query()->findOrFail($validated['product_id']),
            (string) $validated['action'],
            (string) $validated['quantity'],
            isset($validated['product_variant_id']) ? ProductVariant::query()->findOrFail($validated['product_variant_id']) : null,
            $validated['notes'] ?? null,
        );

        return APIResponse::created($this->presenter->adjustmentItem($item->load(['product:id,name,sku', 'variant:id,sku'])), 'Line added');
    }

    public function update(Request $request, StockAdjustment $adjustment, int $item): JsonResponse
    {
        $this->visible($request, $adjustment);
        $line = $this->adjustments->updateItem($this->find($adjustment, $item), $request->only(['action', 'quantity', 'notes']));

        return APIResponse::success($this->presenter->adjustmentItem($line->load(['product:id,name,sku', 'variant:id,sku'])), 'Line updated');
    }

    public function destroy(Request $request, StockAdjustment $adjustment, int $item): JsonResponse
    {
        $this->visible($request, $adjustment);
        $this->adjustments->removeItem($this->find($adjustment, $item));

        return APIResponse::noContent('Line removed');
    }

    private function visible(Request $request, StockAdjustment $adjustment): void
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $this->adjustments->assertVisible($adjustment, $viewer);
    }

    private function find(StockAdjustment $adjustment, int $id): StockAdjustmentItem
    {
        /** @var StockAdjustmentItem */
        return StockAdjustmentItem::query()->where('stock_adjustment_id', $adjustment->id)->whereKey($id)->firstOrFail();
    }
}
