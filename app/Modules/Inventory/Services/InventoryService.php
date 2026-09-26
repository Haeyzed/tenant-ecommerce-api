<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBundleItem;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockAlerts;
use App\Modules\Inventory\Support\StockChange;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of stock (spec §32.6). Every mutation runs in a tenant
 * transaction (joining the caller's), locks the affected inventory rows in
 * ascending (warehouse_id, product_id, variant_key) order in one query,
 * writes one ledger row per changed inventory row (§32.8) and evaluates the
 * stock hooks (§32.7). A deadlock is retried up to 3 times at the outermost
 * transaction. Quantities are decimal strings (scale 3).
 */
final readonly class InventoryService
{
    public function __construct(
        private StockAlerts $alerts,
        private TenantSettingsService $settings,
    ) {}

    // ---- Reads ---------------------------------------------------------

    /**
     * @return array{total_quantity: string, total_reserved: string, total_available: string, warehouses: list<array<string, mixed>>}
     */
    public function getStockForProduct(Product $product, ?ProductVariant $variant = null): array
    {
        $rows = Inventory::query()->with('warehouse:id,name,is_active')
            ->where('product_id', $product->id)
            ->where('variant_key', $variant?->id ?? 0)
            ->orderBy('warehouse_id')
            ->get();

        $quantity = $reserved = Quantity::normalize(0);

        foreach ($rows as $row) {
            $quantity = Quantity::add($quantity, (string) $row->quantity);
            $reserved = Quantity::add($reserved, (string) $row->reserved_quantity);
        }

        return [
            'total_quantity' => $quantity,
            'total_reserved' => $reserved,
            'total_available' => Quantity::sub($quantity, $reserved),
            'warehouses' => $rows->map(static fn (Inventory $row): array => [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_name' => $row->warehouse->name,
                'is_active' => $row->warehouse->is_active,
                'quantity' => (string) $row->quantity,
                'reserved_quantity' => (string) $row->reserved_quantity,
                'available' => $row->available(),
            ])->values()->all(),
        ];
    }

    /**
     * Available stock across active warehouses (or at one warehouse).
     * Bundles derive theirs from their children; digital and service
     * products are not tracked (null).
     */
    public function getAvailableStock(Product $product, ?ProductVariant $variant = null, ?Warehouse $warehouse = null): ?string
    {
        if (! $product->isPhysical()) {
            return null;
        }

        if ($product->product_type === Product::BUNDLE) {
            return $this->bundleAvailable($product, $warehouse?->id);
        }

        // A variable product without a variant: all its active variants.
        return $this->sumAvailable($product->id, $variant?->id ?? ($product->product_type === Product::VARIABLE ? null : 0), $warehouse?->id);
    }

    /**
     * In-stock flags of physical products in one pass (storefront cards).
     *
     * @param  Collection<int, Product>  $products  physical products
     * @return array<int, bool>
     */
    public function inStockMap(Collection $products): array
    {
        $plain = $products->filter(static fn (Product $p): bool => $p->product_type !== Product::BUNDLE)->pluck('id')->all();
        $stocked = $plain === [] ? [] : $this->stockedRows()->whereIn('i.product_id', $plain)->pluck('product_id')->map(static fn ($id): int => (int) $id)->flip()->all();

        $map = [];

        foreach ($products as $product) {
            $map[$product->id] = $product->product_type === Product::BUNDLE
                ? Quantity::isPositive($this->bundleAvailable($product, null))
                : isset($stocked[$product->id]);
        }

        return $map;
    }

    /**
     * Restricts a product query to physical products with available stock:
     * a simple product or any active variant with stock, or a bundle whose
     * every line is covered.
     *
     * @param  EloquentBuilder<Product>  $query
     */
    public function applyInStockConstraint(EloquentBuilder $query): void
    {
        $stocked = $this->stockedRows();

        $query->where(static function (EloquentBuilder $q) use ($stocked): void {
            $q->where(static fn (EloquentBuilder $s) => $s->whereIn('products.product_type', [Product::SIMPLE, Product::VARIABLE])->whereIn('products.id', $stocked))
                ->orWhere(static fn (EloquentBuilder $b) => $b->where('products.product_type', Product::BUNDLE)
                    ->whereExists(static fn (Builder $e) => $e->from('product_bundle_items as bi')->whereColumn('bi.bundle_product_id', 'products.id'))
                    ->whereNotExists(static fn (Builder $e) => $e->from('product_bundle_items as bi')
                        ->whereColumn('bi.bundle_product_id', 'products.id')
                        ->whereRaw('COALESCE((SELECT SUM(ii.quantity - ii.reserved_quantity) FROM inventory ii'
                            .' JOIN warehouses ww ON ww.id = ii.warehouse_id AND ww.is_active = 1'
                            .' WHERE ii.product_id = bi.child_product_id AND ii.variant_key = COALESCE(bi.child_product_variant_id, 0)), 0) < bi.quantity')));
        });
    }

    /**
     * The active warehouse with the lowest id that can supply the whole
     * line (§32.4, Assumption A-15: a line is never split).
     */
    public function selectFulfillmentWarehouse(Product $product, ?ProductVariant $variant, string $quantity): ?Warehouse
    {
        if (! $product->isPhysical()) {
            return null;
        }

        $quantity = Quantity::normalize($quantity);

        if ($product->product_type === Product::BUNDLE) {
            $items = $product->bundleItems()->get();

            if ($items->isEmpty()) {
                return null;
            }

            foreach (Warehouse::query()->where('is_active', true)->orderBy('id')->get() as $warehouse) {
                $covered = $items->every(fn (ProductBundleItem $item): bool => Quantity::cmp(
                    $this->sumAvailable($item->child_product_id, $item->child_product_variant_id ?? 0, $warehouse->id),
                    Quantity::mul($quantity, (string) $item->quantity),
                ) >= 0);

                if ($covered) {
                    return $warehouse;
                }
            }

            return null;
        }

        $this->assertTracked($product, $variant);

        $id = $this->availableRows()
            ->where('i.product_id', $product->id)
            ->where('i.variant_key', $variant?->id ?? 0)
            ->whereRaw('(i.quantity - i.reserved_quantity) >= ?', [$quantity])
            ->orderBy('i.warehouse_id')
            ->value('i.warehouse_id');

        return $id === null ? null : Warehouse::query()->find($id);
    }

    /**
     * Stock-tracked items (simple products, active variants of variable
     * products) with available stock at or below the low-stock threshold
     * but above zero.
     *
     * @param  array{search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function getLowStockProducts(?Warehouse $warehouse = null, array $filters = []): LengthAwarePaginator
    {
        $threshold = (int) $this->settings->get('low_stock_threshold', 5);

        return $this->stockLevels($warehouse, $filters)
            ->whereRaw('(COALESCE(s.quantity, 0) - COALESCE(s.reserved, 0)) > 0')
            ->whereRaw('(COALESCE(s.quantity, 0) - COALESCE(s.reserved, 0)) <= ?', [$threshold])
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Stock-tracked items with no available stock (including items that
     * never had an inventory row).
     *
     * @param  array{search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function getOutOfStockProducts(?Warehouse $warehouse = null, array $filters = []): LengthAwarePaginator
    {
        return $this->stockLevels($warehouse, $filters)
            ->whereRaw('(COALESCE(s.quantity, 0) - COALESCE(s.reserved, 0)) <= 0')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Stock levels of every tracked item (at one warehouse, or across
     * active ones).
     *
     * @param  array{search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function getStockLevels(?Warehouse $warehouse = null, array $filters = []): LengthAwarePaginator
    {
        return $this->stockLevels($warehouse, $filters)->paginate((int) ($filters['per_page'] ?? 25));
    }

    // ---- Mutations -----------------------------------------------------

    /**
     * Adds (positive delta) or removes (negative delta) on-hand stock.
     * Creates the row if missing. Refuses a result below zero or below the
     * reserved quantity.
     */
    public function adjustStock(Warehouse $warehouse, Product $product, ?ProductVariant $variant, string $delta, string $movementType, ?Model $reference = null, ?string $reason = null, ?string $unitCost = null): Inventory
    {
        return $this->apply([new StockChange($warehouse, $product, $variant, Quantity::normalize($delta), Quantity::normalize(0), $movementType, $reason, $unitCost)], $reference)[0];
    }

    public function reserveStock(Warehouse $warehouse, Product $product, ?ProductVariant $variant, string $quantity, Model $reference): void
    {
        $this->apply($this->expand($warehouse, $product, $variant, $quantity, static fn (string $q): array => ['0', $q], 'reserve'), $reference);
    }

    public function releaseReservedStock(Warehouse $warehouse, Product $product, ?ProductVariant $variant, string $quantity, Model $reference): void
    {
        $this->apply($this->expand($warehouse, $product, $variant, $quantity, static fn (string $q): array => ['0', Quantity::neg($q)], 'release'), $reference);
    }

    public function deductStock(Warehouse $warehouse, Product $product, ?ProductVariant $variant, string $quantity, Model $reference, bool $fromReservation = true): void
    {
        $this->apply($this->expand($warehouse, $product, $variant, $quantity,
            static fn (string $q): array => [Quantity::neg($q), $fromReservation ? Quantity::neg($q) : '0'], 'deduct'), $reference);
    }

    /**
     * Moves a reservation between warehouses in one transaction (§32.4
     * item 4). Order items call this when staff change a line's warehouse.
     */
    public function moveReservation(Warehouse $from, Warehouse $to, Product $product, ?ProductVariant $variant, string $quantity, Model $reference): void
    {
        $this->apply([
            ...$this->expand($from, $product, $variant, $quantity, static fn (string $q): array => ['0', Quantity::neg($q)], 'reservation_move'),
            ...$this->expand($to, $product, $variant, $quantity, static fn (string $q): array => ['0', $q], 'reservation_move'),
        ], $reference);
    }

    /**
     * One stock operation over every line of an order, in a single locked
     * pass (§32.6 lock ordering): bundles expand to their children, digital
     * and service lines skip inventory.
     *
     * @param  list<array{warehouse: Warehouse, product: Product, variant: ProductVariant|null, quantity: string}>  $lines
     * @param  string  $operation  reserve | release | deduct (from the reservation) | deduct_direct | restock
     */
    public function applyOrderLines(array $lines, string $operation, Model $reference, ?string $reason = null): void
    {
        [$deltas, $type] = match ($operation) {
            'reserve' => [static fn (string $q): array => ['0', $q], 'reserve'],
            'release' => [static fn (string $q): array => ['0', Quantity::neg($q)], 'release'],
            'deduct' => [static fn (string $q): array => [Quantity::neg($q), Quantity::neg($q)], 'deduct'],
            'deduct_direct' => [static fn (string $q): array => [Quantity::neg($q), '0'], 'deduct'],
            'restock' => [static fn (string $q): array => [$q, '0'], 'restock_cancel'],
            default => throw new InvalidArgumentException("Unknown stock operation [{$operation}]."),
        };

        $changes = [];

        foreach ($lines as $line) {
            foreach ($this->expand($line['warehouse'], $line['product'], $line['variant'], $line['quantity'], $deltas, $type) as $change) {
                $changes[] = $reason === null ? $change : new StockChange(
                    $change->warehouse, $change->product, $change->variant, $change->quantityDelta, $change->reservedDelta, $change->movementType, $reason,
                );
            }
        }

        $this->apply($changes, $reference);
    }

    /**
     * Applies stock changes atomically: all rows are locked in lock order
     * first, each change is checked against the running balance, and one
     * ledger row is written per change.
     *
     * @param  list<StockChange>  $changes
     * @return list<Inventory> the row of each change, in order
     */
    public function apply(array $changes, ?Model $reference = null): array
    {
        if ($changes === []) {
            return [];
        }

        foreach ($changes as $change) {
            if (! in_array($change->movementType, InventoryMovement::TYPES, true)) {
                throw new InvalidArgumentException("Unknown movement type [{$change->movementType}].");
            }

            $this->assertTracked($change->product, $change->variant);
        }

        return DB::connection('tenant')->transaction(function () use ($changes, $reference): array {
            $keys = [];

            foreach ($changes as $change) {
                $keys[$change->keyString()] = $change->key();
            }

            uasort($keys, static fn (array $a, array $b): int => $a <=> $b);
            $rows = $this->lockRows($keys);

            // Rows that do not exist yet are created and locked in the same order.
            $missing = array_diff_key($keys, $rows);

            if ($missing !== []) {
                foreach ($missing as [$warehouseId, $productId, $variantKey]) {
                    $row = new Inventory;
                    $row->forceFill([
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'product_variant_id' => $variantKey === 0 ? null : $variantKey,
                        'quantity' => '0',
                        'reserved_quantity' => '0',
                    ])->save();
                }

                $rows = $this->lockRows($keys);
            }

            /** @var array<string, array{product: Product, variant: ProductVariant|null, before: string}> $pairs */
            $pairs = [];

            foreach ($changes as $change) {
                $pairKey = $change->product->id.':'.($change->variant?->id ?? 0);
                $pairs[$pairKey] ??= [
                    'product' => $change->product,
                    'variant' => $change->variant,
                    'before' => $this->sumAvailable($change->product->id, $change->variant?->id ?? 0, null),
                ];
            }

            $userId = Auth::user() instanceof User ? (int) Auth::id() : null;
            $results = [];

            foreach ($changes as $change) {
                $row = $rows[$change->keyString()];
                $quantity = Quantity::add((string) $row->quantity, $change->quantityDelta);
                $reserved = Quantity::add((string) $row->reserved_quantity, $change->reservedDelta);

                if (Quantity::cmp($reserved, '0') < 0) {
                    throw ApiException::unprocessable('reservation_not_found', 'Less stock is reserved than this change releases.', [
                        'warehouse_id' => $row->warehouse_id, 'product_id' => $row->product_id, 'product_variant_id' => $row->product_variant_id,
                    ]);
                }

                if (Quantity::cmp($quantity, '0') < 0 || Quantity::cmp($reserved, $quantity) > 0) {
                    $requested = Quantity::add(Quantity::neg(Quantity::min($change->quantityDelta, '0')), Quantity::isPositive($change->reservedDelta) ? $change->reservedDelta : '0');

                    throw InsufficientStockException::for($row->warehouse_id, $row->product_id, $row->product_variant_id, $row->available(), $requested);
                }

                $row->forceFill(['quantity' => $quantity, 'reserved_quantity' => $reserved])->save();

                $movement = new InventoryMovement;
                $movement->forceFill([
                    'inventory_id' => $row->id,
                    'warehouse_id' => $row->warehouse_id,
                    'product_id' => $row->product_id,
                    'product_variant_id' => $row->product_variant_id,
                    'movement_type' => $change->movementType,
                    'quantity_delta' => $change->quantityDelta,
                    'reserved_delta' => $change->reservedDelta,
                    'quantity_after' => $quantity,
                    'reserved_after' => $reserved,
                    'unit_cost_snapshot' => $change->unitCost,
                    'reference_type' => $reference?->getMorphClass(),
                    'reference_id' => $reference?->getKey(),
                    'reason' => $change->reason === null ? null : mb_substr($change->reason, 0, 255),
                    'user_id' => $userId,
                ])->save();

                $results[] = $row;
            }

            foreach ($pairs as $pair) {
                $after = $this->sumAvailable($pair['product']->id, $pair['variant']?->id ?? 0, null);
                $this->alerts->evaluate($pair['product'], $pair['variant'], $pair['before'], $after);
            }

            return $results;
        }, 3);
    }

    /**
     * Operational repair (§32.8): recomputes a row's balance from its
     * ledger. Never run automatically.
     */
    public function rebuildBalance(Inventory $row): Inventory
    {
        return DB::connection('tenant')->transaction(function () use ($row): Inventory {
            /** @var Inventory $locked */
            $locked = Inventory::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $sums = $this->ledgerSums()->where('inventory_id', $locked->id)->first();

            $locked->forceFill([
                'quantity' => Quantity::normalize((string) ($sums->quantity ?? 0)),
                'reserved_quantity' => Quantity::normalize((string) ($sums->reserved ?? 0)),
            ])->save();

            return $locked;
        });
    }

    /**
     * Rows whose balance differs from their ledger (inventory:verify).
     *
     * @return Collection<int, object{id: int, warehouse_id: int, product_id: int, product_variant_id: int|null, quantity: string, reserved_quantity: string, ledger_quantity: string, ledger_reserved: string}>
     */
    public function drift(): Collection
    {
        return DB::connection('tenant')->table('inventory as i')
            ->leftJoinSub($this->ledgerSums(), 'm', 'm.inventory_id', '=', 'i.id')
            ->whereRaw('(i.quantity <> COALESCE(m.quantity, 0) OR i.reserved_quantity <> COALESCE(m.reserved, 0))')
            ->orderBy('i.id')
            ->get(['i.id', 'i.warehouse_id', 'i.product_id', 'i.product_variant_id', 'i.quantity', 'i.reserved_quantity',
                DB::raw('COALESCE(m.quantity, 0) as ledger_quantity'), DB::raw('COALESCE(m.reserved, 0) as ledger_reserved')]);
    }

    // ---- Internals -----------------------------------------------------

    /**
     * Simple products hold stock without a variant, variable products per
     * variant; bundles, digital and service products hold none of their own.
     */
    public function assertTracked(Product $product, ?ProductVariant $variant): void
    {
        if ($product->product_type === Product::SIMPLE) {
            if ($variant !== null) {
                throw ApiException::unprocessable('variant_not_applicable', 'A simple product has no variants.');
            }

            return;
        }

        if ($product->product_type === Product::VARIABLE) {
            if ($variant === null || $variant->product_id !== $product->id) {
                throw ApiException::unprocessable('variant_required', 'Choose a variant of this product.');
            }

            return;
        }

        throw ApiException::unprocessable('product_not_stock_tracked', 'Bundles, digital and service products hold no stock of their own.');
    }

    /**
     * Order-level stock operations: bundles expand to their children,
     * digital and service products skip inventory (§32.5).
     *
     * @param  callable(string): array{0: string, 1: string}  $deltas  quantity => [quantity delta, reserved delta]
     * @return list<StockChange>
     */
    private function expand(Warehouse $warehouse, Product $product, ?ProductVariant $variant, string $quantity, callable $deltas, string $type): array
    {
        $quantity = Quantity::normalize($quantity);

        if (! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('Stock operations take a positive quantity.');
        }

        if (! $product->isPhysical()) {
            return [];
        }

        if ($product->product_type === Product::BUNDLE) {
            $changes = [];

            foreach ($product->bundleItems()->with(['child', 'childVariant'])->get() as $item) {
                $changes = [...$changes, ...$this->expand($warehouse, $item->child, $item->childVariant, Quantity::mul($quantity, (string) $item->quantity), $deltas, $type)];
            }

            return $changes;
        }

        [$quantityDelta, $reservedDelta] = $deltas($quantity);

        return [new StockChange($warehouse, $product, $variant, Quantity::normalize($quantityDelta), Quantity::normalize($reservedDelta), $type)];
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: int}>  $keys  sorted
     * @return array<string, Inventory>
     */
    private function lockRows(array $keys): array
    {
        return Inventory::query()
            ->where(static function (EloquentBuilder $q) use ($keys): void {
                foreach ($keys as [$warehouseId, $productId, $variantKey]) {
                    $q->orWhere(static fn (EloquentBuilder $o) => $o->where('warehouse_id', $warehouseId)->where('product_id', $productId)->where('variant_key', $variantKey));
                }
            })
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->orderBy('variant_key')
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (Inventory $row): string => $row->warehouse_id.':'.$row->product_id.':'.$row->variant_key)
            ->all();
    }

    /**
     * Inventory rows that count as sellable: active warehouses, and no
     * inactive or deleted variant.
     */
    private function availableRows(): Builder
    {
        return DB::connection('tenant')->table('inventory as i')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->where('w.is_active', true)
            ->where(static fn (Builder $q) => $q->whereNull('i.product_variant_id')
                ->orWhere(static fn (Builder $v) => $v->where('v.is_active', true)->whereNull('v.deleted_at')));
    }

    /**
     * product_id of every (product, variant) with available stock.
     */
    private function stockedRows(): Builder
    {
        return $this->availableRows()
            ->select('i.product_id')
            ->groupBy('i.product_id', 'i.variant_key')
            ->havingRaw('SUM(i.quantity - i.reserved_quantity) > 0');
    }

    /**
     * @param  int|null  $variantKey  null = every variant of the product
     */
    private function sumAvailable(int $productId, ?int $variantKey, ?int $warehouseId): string
    {
        $sum = $this->availableRows()
            ->where('i.product_id', $productId)
            ->when($variantKey !== null, static fn (Builder $q) => $q->where('i.variant_key', $variantKey))
            ->when($warehouseId !== null, static fn (Builder $q) => $q->where('i.warehouse_id', $warehouseId))
            ->sum(DB::raw('i.quantity - i.reserved_quantity'));

        return Quantity::normalize((string) $sum);
    }

    private function bundleAvailable(Product $bundle, ?int $warehouseId): string
    {
        $items = $bundle->relationLoaded('bundleItems') ? $bundle->bundleItems : $bundle->bundleItems()->get();
        $units = null;

        foreach ($items as $item) {
            $child = $this->sumAvailable($item->child_product_id, $item->child_product_variant_id ?? 0, $warehouseId);
            $fit = Quantity::wholeUnits($child, (string) $item->quantity);
            $units = $units === null ? $fit : Quantity::min($units, $fit);
        }

        return $units ?? Quantity::normalize(0);
    }

    /**
     * @param  array{search?: string}  $filters
     */
    private function stockLevels(?Warehouse $warehouse, array $filters): Builder
    {
        $sums = DB::connection('tenant')->table('inventory as si')
            ->join('warehouses as sw', 'sw.id', '=', 'si.warehouse_id')
            ->when($warehouse !== null,
                static fn (Builder $q) => $q->where('si.warehouse_id', $warehouse?->id),
                static fn (Builder $q) => $q->where('sw.is_active', true))
            ->groupBy('si.product_id', 'si.variant_key')
            ->selectRaw('si.product_id, si.variant_key, SUM(si.quantity) as quantity, SUM(si.reserved_quantity) as reserved');

        $search = trim((string) ($filters['search'] ?? ''));

        return DB::connection('tenant')->table('products as p')
            ->leftJoin('product_variants as v', static fn ($j) => $j->on('v.product_id', '=', 'p.id')
                ->where('p.product_type', '=', Product::VARIABLE)
                ->where('v.is_active', '=', true)
                ->whereNull('v.deleted_at'))
            ->leftJoinSub($sums, 's', static fn ($j) => $j->on('s.product_id', '=', 'p.id')->on('s.variant_key', '=', DB::raw('COALESCE(v.id, 0)')))
            ->whereNull('p.deleted_at')
            ->where(static fn (Builder $q) => $q->where('p.product_type', Product::SIMPLE)
                ->orWhere(static fn (Builder $v) => $v->where('p.product_type', Product::VARIABLE)->whereNotNull('v.id')))
            ->when($search !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w
                ->where('p.name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('p.sku', $search)
                ->orWhere('v.sku', $search)))
            ->selectRaw('p.id as product_id, p.name as product_name, v.id as product_variant_id, COALESCE(v.sku, p.sku) as sku,'
                .' COALESCE(s.quantity, 0) as quantity, COALESCE(s.reserved, 0) as reserved_quantity,'
                .' (COALESCE(s.quantity, 0) - COALESCE(s.reserved, 0)) as available')
            ->orderByRaw('(COALESCE(s.quantity, 0) - COALESCE(s.reserved, 0)) asc')
            ->orderBy('p.id')
            ->orderBy('v.id');
    }

    private function ledgerSums(): Builder
    {
        return DB::connection('tenant')->table('inventory_movements')
            ->groupBy('inventory_id')
            ->selectRaw('inventory_id, SUM(quantity_delta) as quantity, SUM(reserved_delta) as reserved');
    }
}
