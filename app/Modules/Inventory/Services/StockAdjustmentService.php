<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockAdjustmentItem;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockChange;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Stock adjustments (spec §33.2): a draft batch per warehouse, submitted
 * all-or-nothing through InventoryService, immutable afterwards. A mistake
 * is corrected with a new, opposite adjustment.
 */
final readonly class StockAdjustmentService
{
    private const int LOOKUP_LIMIT = 20;

    public function __construct(
        private InventoryService $inventory,
        private WarehouseService $warehouses,
        private StorageQuota $quota,
    ) {}

    public function createAdjustment(Warehouse $warehouse, ?string $notes, ?UploadedFile $attachment, User $by): StockAdjustment
    {
        validator(['notes' => $notes, 'attachment' => $attachment], [
            'notes' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', ...UploadRules::document()],
        ])->validate();

        if (! $warehouse->is_active) {
            throw ApiException::unprocessable('warehouse_inactive', 'The warehouse is inactive.');
        }

        if ($attachment !== null) {
            $this->quota->assertAllows($attachment);
        }

        return DB::connection('tenant')->transaction(static function () use ($warehouse, $notes, $attachment, $by): StockAdjustment {
            $adjustment = new StockAdjustment(['notes' => $notes]);
            $adjustment->forceFill(['warehouse_id' => $warehouse->id, 'status' => StockAdjustment::DRAFT, 'created_by' => $by->id])->save();

            if ($attachment !== null) {
                $media = $adjustment->addMedia($attachment)
                    ->usingFileName(Str::uuid().'.'.$attachment->guessExtension())
                    ->usingName(mb_substr(pathinfo($attachment->getClientOriginalName(), PATHINFO_FILENAME), 0, 200))
                    ->toMediaCollection('attachment');
                $adjustment->forceFill(['attachment_media_id' => $media->id])->save();
            }

            return $adjustment;
        });
    }

    /**
     * The line's unit cost is captured now (variant cost, else product
     * cost) for valuation.
     */
    public function addItem(StockAdjustment $adjustment, Product $product, string $action, string $quantity, ?ProductVariant $variant = null, ?string $notes = null): StockAdjustmentItem
    {
        $this->validateLine(['action' => $action, 'quantity' => $quantity, 'notes' => $notes], true);
        $this->inventory->assertTracked($product, $variant);

        return DB::connection('tenant')->transaction(function () use ($adjustment, $product, $action, $quantity, $variant, $notes): StockAdjustmentItem {
            $this->lockDraft($adjustment);
            $cost = $variant?->cost_price ?? $product->cost_price;

            /** @var StockAdjustmentItem */
            return $adjustment->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'action' => $action,
                'quantity' => Quantity::normalize($quantity),
                'unit_cost_snapshot' => $cost !== null ? (string) $cost : null,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data  action, quantity, notes
     */
    public function updateItem(StockAdjustmentItem $item, array $data): StockAdjustmentItem
    {
        $validated = $this->validateLine($data, false);

        DB::connection('tenant')->transaction(function () use ($item, $validated): void {
            $this->lockDraft($item->adjustment()->firstOrFail());

            if (isset($validated['quantity'])) {
                $validated['quantity'] = Quantity::normalize((string) $validated['quantity']);
            }

            $item->fill($validated)->save();
        });

        return $item;
    }

    public function removeItem(StockAdjustmentItem $item): void
    {
        DB::connection('tenant')->transaction(function () use ($item): void {
            $this->lockDraft($item->adjustment()->firstOrFail());
            $item->delete();
        });
    }

    public function submitAdjustment(StockAdjustment $adjustment): StockAdjustment
    {
        DB::connection('tenant')->transaction(function () use ($adjustment): void {
            $locked = $this->lockDraft($adjustment);
            $locked->load(['warehouse', 'items.product', 'items.variant']);

            if ($locked->items->isEmpty()) {
                throw ApiException::unprocessable('adjustment_empty', 'Add at least one line before submitting.');
            }

            $this->inventory->apply($locked->items->map(static function (StockAdjustmentItem $item) use ($locked): StockChange {
                $addition = $item->action === StockAdjustmentItem::ADDITION;

                return new StockChange(
                    $locked->warehouse, $item->product, $item->variant,
                    $addition ? (string) $item->quantity : Quantity::neg((string) $item->quantity), Quantity::normalize(0),
                    $addition ? 'adjustment_in' : 'adjustment_out',
                    $item->notes ?? $locked->notes,
                    $item->unit_cost_snapshot !== null ? (string) $item->unit_cost_snapshot : null,
                );
            })->values()->all(), $locked);

            $locked->forceFill(['status' => StockAdjustment::SUBMITTED, 'submitted_at' => now()])->save();
        });

        return $this->getAdjustment($adjustment->refresh());
    }

    public function getAdjustment(StockAdjustment $adjustment): StockAdjustment
    {
        return $adjustment->load(['warehouse:id,name', 'creator:id,name', 'items.product:id,name,sku', 'items.variant:id,sku', 'media']);
    }

    /**
     * @param  array{status?: string, warehouse_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, StockAdjustment>
     */
    public function listAdjustments(array $filters, ?User $viewer = null): LengthAwarePaginator
    {
        $visible = $this->warehouses->visibleIds($viewer);

        return StockAdjustment::query()
            ->with(['warehouse:id,name', 'creator:id,name'])
            ->withCount('items')
            ->when($visible !== null, static fn (Builder $q) => $q->whereIn('warehouse_id', $visible))
            ->when($filters['status'] ?? null, static fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['warehouse_id'] ?? null, static fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function assertVisible(StockAdjustment $adjustment, ?User $viewer): void
    {
        $visible = $this->warehouses->visibleIds($viewer);

        if ($visible !== null && ! in_array($adjustment->warehouse_id, $visible, true)) {
            throw (new ModelNotFoundException)->setModel(StockAdjustment::class, [$adjustment->id]);
        }
    }

    /**
     * Stock-tracked products and variants matching a name, SKU or barcode,
     * with their cost and current quantity at the warehouse.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lookupProducts(Warehouse $warehouse, string $search): Collection
    {
        $term = trim($search);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $simple = Product::query()->where('product_type', Product::SIMPLE)
            ->where(static fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('sku', $term)->orWhere('barcode', $term))
            ->orderBy('name')->limit(self::LOOKUP_LIMIT)->get(['id', 'name', 'sku', 'barcode', 'cost_price']);

        $variants = ProductVariant::query()->with('product:id,name,cost_price')
            ->whereHas('product', static fn (Builder $p) => $p->where('product_type', Product::VARIABLE))
            ->where(static fn (Builder $q) => $q->where('sku', $term)->orWhere('barcode', $term)
                ->orWhereHas('product', static fn (Builder $p) => $p->where('name', 'like', $like)))
            ->orderBy('product_id')->orderBy('id')->limit(self::LOOKUP_LIMIT)->get(['id', 'product_id', 'sku', 'barcode', 'cost_price']);

        $stock = Inventory::query()->where('warehouse_id', $warehouse->id)
            ->where(static fn (Builder $q) => $q
                ->where(static fn (Builder $s) => $s->whereIn('product_id', $simple->pluck('id'))->where('variant_key', 0))
                ->orWhereIn('product_variant_id', $variants->pluck('id')))
            ->get()
            ->keyBy(static fn (Inventory $row): string => $row->product_id.':'.$row->variant_key);

        $rows = $simple->map(static fn (Product $p): array => [
            'product_id' => $p->id,
            'product_variant_id' => null,
            'name' => $p->name,
            'code' => $p->sku ?? $p->barcode,
            'cost_price' => $p->cost_price !== null ? (string) $p->cost_price : null,
            'quantity' => (string) ($stock->get($p->id.':0')?->quantity ?? Quantity::normalize(0)),
        ]);

        $rows = $rows->concat($variants->map(static fn (ProductVariant $v): array => [
            'product_id' => $v->product_id,
            'product_variant_id' => $v->id,
            'name' => $v->product->name.' ('.$v->sku.')',
            'code' => $v->sku,
            'cost_price' => ($v->cost_price ?? $v->product->cost_price) !== null ? (string) ($v->cost_price ?? $v->product->cost_price) : null,
            'quantity' => (string) ($stock->get($v->product_id.':'.$v->id)?->quantity ?? Quantity::normalize(0)),
        ]));

        return $rows->take(self::LOOKUP_LIMIT)->values();
    }

    private function lockDraft(StockAdjustment $adjustment): StockAdjustment
    {
        /** @var StockAdjustment $locked */
        $locked = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== StockAdjustment::DRAFT) {
            throw ApiException::unprocessable('adjustment_submitted', 'A submitted adjustment cannot change. Create an opposite adjustment instead.');
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateLine(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return validator($data, [
            'action' => [$req, Rule::in([StockAdjustmentItem::ADDITION, StockAdjustmentItem::SUBTRACTION])],
            'quantity' => [$req, 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();
    }
}
