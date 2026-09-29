<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Manufacturing\Models\BillOfMaterial;
use App\Modules\Manufacturing\Models\BillOfMaterialItem;
use App\Modules\Manufacturing\Models\WorkOrder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Quantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Recipes (spec §64.1, §64.4). The finished item is a simple product or a
 * variant of a variable one; components are stock-holding products (not
 * bundles, digital or service products). Saving a default recipe rolls its
 * cost up to the finished item (§64.3).
 */
final readonly class BillOfMaterialService
{
    public function __construct(private ManufacturingCostService $costs) {}

    /**
     * @param  list<array<string, mixed>>  $items  component_product_id, component_product_variant_id?, quantity_required
     * @param  array<string, mixed>  $data  name, product_variant_id?, yield_quantity?, is_default?, notes?
     */
    public function createBom(Product $product, array $items, array $data): BillOfMaterial
    {
        $validated = $this->validate($data, true);
        $variant = $this->finished($product, $validated['product_variant_id'] ?? null);
        $lines = $this->lines($items, $product, $variant);

        return DB::connection('tenant')->transaction(function () use ($product, $variant, $validated, $lines): BillOfMaterial {
            $bom = new BillOfMaterial;
            $bom->forceFill([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'name' => $validated['name'],
                'yield_quantity' => Quantity::normalize((string) ($validated['yield_quantity'] ?? '1')),
                'notes' => $validated['notes'] ?? null,
                'is_default' => false,
            ])->save();
            $this->replaceItems($bom, $lines);

            // The first recipe of an item is its default unless told otherwise.
            $isDefault = (bool) ($validated['is_default'] ?? ! $this->hasDefault($product->id, $variant?->id));

            return $isDefault ? $this->makeDefault($bom) : $bom->load('items');
        });
    }

    /**
     * Existing work orders keep the materials they were created with.
     *
     * @param  list<array<string, mixed>>|null  $items  null keeps the components
     * @param  array<string, mixed>  $data
     */
    public function updateBom(BillOfMaterial $bom, ?array $items, array $data): BillOfMaterial
    {
        $validated = $this->validate($data, false);
        $bom->loadMissing(['product', 'variant']);
        $lines = $items === null ? null : $this->lines($items, $bom->product, $bom->variant);

        return DB::connection('tenant')->transaction(function () use ($bom, $validated, $lines): BillOfMaterial {
            $changes = array_intersect_key($validated, array_flip(['name', 'notes']));

            if (isset($validated['yield_quantity'])) {
                $changes['yield_quantity'] = Quantity::normalize((string) $validated['yield_quantity']);
            }

            $bom->forceFill($changes)->save();

            if ($lines !== null) {
                $this->replaceItems($bom, $lines);
            }

            if (($validated['is_default'] ?? false) === true || $bom->is_default) {
                return $this->makeDefault($bom->refresh());
            }

            return $bom->load('items');
        });
    }

    public function deleteBom(BillOfMaterial $bom): void
    {
        if (WorkOrder::query()->where('bill_of_material_id', $bom->id)->exists()) {
            throw ApiException::unprocessable('bom_in_use', 'Work orders use this recipe; it cannot be deleted.');
        }

        $bom->delete();
    }

    public function setDefaultBom(BillOfMaterial $bom): BillOfMaterial
    {
        return DB::connection('tenant')->transaction(fn (): BillOfMaterial => $this->makeDefault($bom));
    }

    /**
     * @return Collection<int, BillOfMaterial>
     */
    public function listBoms(Product $product): Collection
    {
        return BillOfMaterial::query()->with(['items.component:id,name,sku,cost_price', 'items.componentVariant:id,sku,cost_price', 'variant:id,sku'])
            ->where('product_id', $product->id)->orderByDesc('is_default')->orderBy('id')->get();
    }

    public function getBom(BillOfMaterial $bom): BillOfMaterial
    {
        return $bom->load(['product:id,name,sku,product_type,cost_price', 'variant:id,sku,cost_price', 'items.component:id,name,sku,cost_price', 'items.componentVariant:id,sku,cost_price']);
    }

    /**
     * Inside a transaction: the only default of its item; the cost rolls up.
     */
    private function makeDefault(BillOfMaterial $bom): BillOfMaterial
    {
        BillOfMaterial::query()->where('product_id', $bom->product_id)
            ->where(static fn ($q) => $bom->product_variant_id === null ? $q->whereNull('product_variant_id') : $q->where('product_variant_id', $bom->product_variant_id))
            ->whereKeyNot($bom->id)->where('is_default', true)->update(['is_default' => false, 'updated_at' => now()]);
        $bom->forceFill(['is_default' => true])->save();
        $this->costs->applyToProduct($bom->load(['items.component', 'items.componentVariant', 'product', 'variant']));

        return $bom->load('items');
    }

    private function hasDefault(int $productId, ?int $variantId): bool
    {
        return BillOfMaterial::query()->where('product_id', $productId)->where('is_default', true)
            ->where(static fn ($q) => $variantId === null ? $q->whereNull('product_variant_id') : $q->where('product_variant_id', $variantId))->exists();
    }

    /**
     * @param  list<array{product: Product, variant: ProductVariant|null, quantity: string}>  $lines
     */
    private function replaceItems(BillOfMaterial $bom, array $lines): void
    {
        BillOfMaterialItem::query()->where('bill_of_material_id', $bom->id)->delete();

        foreach ($lines as $line) {
            $cost = $line['variant']?->cost_price ?? $line['product']->cost_price;
            $item = new BillOfMaterialItem;
            $item->forceFill([
                'bill_of_material_id' => $bom->id,
                'component_product_id' => $line['product']->id,
                'component_product_variant_id' => $line['variant']?->id,
                'quantity_required' => $line['quantity'],
                'unit_cost_snapshot' => $cost === null ? null : (string) $cost,
            ])->save();
        }
    }

    /**
     * The finished item: a simple product, or a variant of a variable one.
     */
    private function finished(Product $product, mixed $variantId): ?ProductVariant
    {
        if (! in_array($product->product_type, [Product::SIMPLE, Product::VARIABLE], true)) {
            throw ApiException::unprocessable('bom_product_invalid', 'Only simple and variable products can be manufactured.');
        }

        if ($product->product_type === Product::SIMPLE) {
            if ($variantId !== null) {
                throw ApiException::unprocessable('bom_product_invalid', 'A simple product has no variants.');
            }

            return null;
        }

        return ($variantId === null ? null : ProductVariant::query()->where('product_id', $product->id)->find((int) $variantId))
            ?? throw ApiException::unprocessable('variant_required', 'Choose the variant this recipe makes.');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{product: Product, variant: ProductVariant|null, quantity: string}>
     */
    private function lines(array $items, Product $product, ?ProductVariant $variant): array
    {
        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.component_product_id' => ['required', 'integer'],
            'items.*.component_product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.quantity_required' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ])->validate();

        $lines = [];
        $seen = [];

        foreach ($items as $i => $item) {
            /** @var Product|null $component */
            $component = Product::query()->find((int) $item['component_product_id']);
            $componentVariant = isset($item['component_product_variant_id'])
                ? ProductVariant::query()->where('product_id', (int) $item['component_product_id'])->find((int) $item['component_product_variant_id']) : null;

            $valid = $component !== null && match ($component->product_type) {
                Product::SIMPLE => $componentVariant === null,
                Product::VARIABLE => $componentVariant !== null,
                default => false,
            };

            if (! $valid) {
                throw ApiException::unprocessable('bom_component_invalid', 'Components must be stock-holding products; a variable product needs its variant.', ['line' => $i]);
            }

            $key = $component->id.':'.($componentVariant?->id ?? 0);

            if ($key === $product->id.':'.($variant?->id ?? 0) || isset($seen[$key])) {
                throw ApiException::unprocessable('bom_component_invalid', 'A component appears twice, or is the item being made.', ['line' => $i]);
            }

            $seen[$key] = true;
            $lines[] = ['product' => $component, 'variant' => $componentVariant, 'quantity' => Quantity::normalize((string) $item['quantity_required'])];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        return Validator::make($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'product_variant_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'integer'],
            'yield_quantity' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
            'is_default' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ])->validate();
    }
}
