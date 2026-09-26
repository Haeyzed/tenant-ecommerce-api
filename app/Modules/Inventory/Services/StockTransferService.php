<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockChange;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Stock transfers between warehouses (spec §33.1). Dispatch takes the stock
 * out of the source at once; receipt (possibly partial) puts it into the
 * destination; cancelling in transit returns the unreceived remainder to
 * the source. Status changes lock the transfer row.
 */
final readonly class StockTransferService
{
    public function __construct(
        private InventoryService $inventory,
        private WarehouseService $warehouses,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  {product_id, product_variant_id?, quantity}
     */
    public function createTransfer(Warehouse $from, Warehouse $to, array $items, ?string $notes, User $by): StockTransfer
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_warehouse_id' => ['The destination must differ from the source.']]);
        }

        if (! $from->is_active || ! $to->is_active) {
            throw ApiException::unprocessable('warehouse_inactive', 'Both warehouses must be active.');
        }

        $lines = $this->validateItems($items);

        return DB::connection('tenant')->transaction(static function () use ($from, $to, $lines, $notes, $by): StockTransfer {
            $transfer = new StockTransfer(['notes' => $notes]);
            $transfer->forceFill([
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'status' => StockTransfer::DRAFT,
                'created_by' => $by->id,
            ])->save();

            foreach ($lines as $line) {
                $transfer->items()->create($line);
            }

            return $transfer;
        });
    }

    /**
     * Draft only: replaces the lines and notes.
     *
     * @param  list<array<string, mixed>>|null  $items
     */
    public function updateTransfer(StockTransfer $transfer, ?array $items, ?string $notes): StockTransfer
    {
        $lines = $items === null ? null : $this->validateItems($items);

        DB::connection('tenant')->transaction(function () use ($transfer, $lines, $notes): void {
            $locked = $this->lock($transfer);

            if ($locked->status !== StockTransfer::DRAFT) {
                throw ApiException::unprocessable('transfer_not_draft', 'Only a draft transfer can be edited.');
            }

            if ($notes !== null) {
                $locked->fill(['notes' => $notes])->save();
            }

            if ($lines !== null) {
                $locked->items()->delete();

                foreach ($lines as $line) {
                    $locked->items()->create($line);
                }
            }
        });

        return $this->getTransfer($transfer->refresh());
    }

    public function dispatchTransfer(StockTransfer $transfer): StockTransfer
    {
        DB::connection('tenant')->transaction(function () use ($transfer): void {
            $locked = $this->lock($transfer);

            if ($locked->status !== StockTransfer::DRAFT) {
                throw ApiException::invalidTransition($locked->status, StockTransfer::IN_TRANSIT);
            }

            $locked->load(['fromWarehouse', 'items.product', 'items.variant']);

            if (! $locked->fromWarehouse->is_active) {
                throw ApiException::unprocessable('warehouse_inactive', 'The source warehouse is inactive.');
            }

            $this->inventory->apply($locked->items->map(static fn (StockTransferItem $item): StockChange => new StockChange(
                $locked->fromWarehouse, $item->product, $item->variant,
                Quantity::neg((string) $item->quantity_sent), Quantity::normalize(0), 'transfer_out',
            ))->values()->all(), $locked);

            $locked->forceFill(['status' => StockTransfer::IN_TRANSIT, 'dispatched_at' => now()])->save();
        });

        return $this->getTransfer($transfer->refresh());
    }

    /**
     * @param  list<array<string, mixed>>  $receivedItems  {item_id, quantity}
     */
    public function receiveTransfer(StockTransfer $transfer, array $receivedItems): StockTransfer
    {
        $received = validator(['items' => $receivedItems], [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ])->validate()['items'];

        DB::connection('tenant')->transaction(function () use ($transfer, $received): void {
            $locked = $this->lock($transfer);

            if ($locked->status !== StockTransfer::IN_TRANSIT) {
                throw ApiException::invalidTransition($locked->status, StockTransfer::RECEIVED);
            }

            $locked->load(['toWarehouse', 'items.product', 'items.variant']);
            $items = $locked->items->keyBy('id');
            $changes = [];

            foreach ($received as $index => $line) {
                /** @var StockTransferItem|null $item */
                $item = $items->get((int) $line['item_id']);
                $quantity = Quantity::normalize((string) $line['quantity']);

                if ($item === null) {
                    throw ValidationException::withMessages(["items.{$index}.item_id" => ['The line is not part of this transfer.']]);
                }

                if (Quantity::cmp($quantity, $item->remaining()) > 0) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => ["At most {$item->remaining()} remains to be received on this line."]]);
                }

                $changes[] = new StockChange($locked->toWarehouse, $item->product, $item->variant, $quantity, Quantity::normalize(0), 'transfer_in');
                $item->forceFill(['quantity_received' => Quantity::add((string) $item->quantity_received, $quantity)])->save();
            }

            $this->inventory->apply($changes, $locked);

            if ($locked->items->every(static fn (StockTransferItem $item): bool => ! Quantity::isPositive($item->remaining()))) {
                $locked->forceFill(['status' => StockTransfer::RECEIVED, 'received_at' => now()])->save();
            }
        });

        return $this->getTransfer($transfer->refresh());
    }

    public function cancelTransfer(StockTransfer $transfer): StockTransfer
    {
        DB::connection('tenant')->transaction(function () use ($transfer): void {
            $locked = $this->lock($transfer);

            if (! in_array($locked->status, [StockTransfer::DRAFT, StockTransfer::IN_TRANSIT], true)) {
                throw ApiException::invalidTransition($locked->status, StockTransfer::CANCELLED);
            }

            if ($locked->status === StockTransfer::IN_TRANSIT) {
                $locked->load(['fromWarehouse', 'items.product', 'items.variant']);

                $this->inventory->apply($locked->items
                    ->filter(static fn (StockTransferItem $item): bool => Quantity::isPositive($item->remaining()))
                    ->map(static fn (StockTransferItem $item): StockChange => new StockChange(
                        $locked->fromWarehouse, $item->product, $item->variant,
                        $item->remaining(), Quantity::normalize(0), 'transfer_in', 'Transfer cancelled',
                    ))->values()->all(), $locked);
            }

            $locked->forceFill(['status' => StockTransfer::CANCELLED])->save();
        });

        return $this->getTransfer($transfer->refresh());
    }

    /**
     * @param  array{status?: string, warehouse_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, StockTransfer>
     */
    public function listTransfers(array $filters, ?User $viewer = null): LengthAwarePaginator
    {
        $visible = $this->warehouses->visibleIds($viewer);

        return StockTransfer::query()
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name'])
            ->withCount('items')
            ->when($visible !== null, static fn (Builder $q) => $q->where(static fn (Builder $w) => $w->whereIn('from_warehouse_id', $visible)->orWhereIn('to_warehouse_id', $visible)))
            ->when($filters['status'] ?? null, static fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['warehouse_id'] ?? null, static fn (Builder $q, $v) => $q->where(static fn (Builder $w) => $w->where('from_warehouse_id', $v)->orWhere('to_warehouse_id', $v)))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function getTransfer(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load(['fromWarehouse:id,name', 'toWarehouse:id,name', 'creator:id,name', 'items.product:id,name,sku', 'items.variant:id,sku']);
    }

    public function assertVisible(StockTransfer $transfer, ?User $viewer): void
    {
        $visible = $this->warehouses->visibleIds($viewer);

        if ($visible !== null && ! in_array($transfer->from_warehouse_id, $visible, true) && ! in_array($transfer->to_warehouse_id, $visible, true)) {
            throw (new ModelNotFoundException)->setModel(StockTransfer::class, [$transfer->id]);
        }
    }

    private function lock(StockTransfer $transfer): StockTransfer
    {
        /** @var StockTransfer */
        return StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{product_id: int, product_variant_id: int|null, quantity_sent: string}>
     */
    private function validateItems(array $items): array
    {
        $validated = validator(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')],
            'items.*.product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
        ])->validate()['items'];

        $lines = [];
        $seen = [];

        foreach ($validated as $index => $line) {
            $product = Product::query()->findOrFail((int) $line['product_id']);
            $variant = isset($line['product_variant_id']) ? ProductVariant::query()->find((int) $line['product_variant_id']) : null;

            if (isset($line['product_variant_id']) && $variant === null) {
                throw ValidationException::withMessages(["items.{$index}.product_variant_id" => ['The variant does not exist.']]);
            }

            $this->inventory->assertTracked($product, $variant);
            $key = $product->id.':'.($variant?->id ?? 0);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => ['Each product or variant appears once per transfer.']]);
            }

            $seen[$key] = true;
            $lines[] = ['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity_sent' => Quantity::normalize((string) $line['quantity'])];
        }

        return $lines;
    }
}
