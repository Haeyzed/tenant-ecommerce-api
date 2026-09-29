<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Support\StockChange;
use App\Modules\Manufacturing\Models\BillOfMaterial;
use App\Modules\Manufacturing\Models\WorkOrder;
use App\Modules\Manufacturing\Models\WorkOrderMaterial;
use App\Modules\Users\Models\User;
use App\Modules\Users\Support\StaffAccessScope;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Work orders (spec §64.2). Starting reserves every component at the
 * warehouse in one locked pass (a shortfall is refused up front, 409);
 * completing consumes the reservations and adds the finished goods in one
 * transaction; cancelling releases what was reserved.
 */
final readonly class WorkOrderService
{
    public function __construct(
        private InventoryService $inventory,
        private ManufacturingCostService $costs,
        private StaffAccessScope $scope,
    ) {}

    /**
     * Materials = BOM quantity × quantity ÷ yield, rounded up to three
     * places so production never draws less than the recipe needs.
     *
     * @param  array<string, mixed>  $data  scheduled_start_date?, scheduled_end_date?, notes?
     */
    public function createWorkOrder(BillOfMaterial $bom, Warehouse $warehouse, string $quantity, array $data, User $by): WorkOrder
    {
        $validated = Validator::make([...$data, 'quantity' => $quantity], [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'scheduled_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'scheduled_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:scheduled_start_date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ])->validate();

        if (! $warehouse->is_active) {
            throw ApiException::unprocessable('warehouse_inactive', 'Choose an active warehouse.');
        }

        $bom->loadMissing('items');

        if ($bom->items->isEmpty()) {
            throw ApiException::unprocessable('bom_empty', 'This recipe has no components.');
        }

        $quantity = Quantity::normalize((string) $validated['quantity']);

        return DB::connection('tenant')->transaction(function () use ($bom, $warehouse, $quantity, $validated, $by): WorkOrder {
            $order = new WorkOrder;
            $order->forceFill([
                'work_order_number' => $this->nextNumber(),
                'bill_of_material_id' => $bom->id,
                'warehouse_id' => $warehouse->id,
                'quantity_to_produce' => $quantity,
                'status' => WorkOrder::PLANNED,
                'scheduled_start_date' => $validated['scheduled_start_date'] ?? null,
                'scheduled_end_date' => $validated['scheduled_end_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $by->id,
            ])->save();

            foreach ($bom->items as $item) {
                $material = new WorkOrderMaterial;
                $material->forceFill([
                    'work_order_id' => $order->id,
                    'component_product_id' => $item->component_product_id,
                    'component_product_variant_id' => $item->component_product_variant_id,
                    'quantity_required' => self::ceil3(bcdiv(bcmul((string) $item->quantity_required, $quantity, 8), (string) $bom->yield_quantity, 8)),
                ])->save();
            }

            return $order->load('materials');
        });
    }

    public function startWorkOrder(WorkOrder $order): WorkOrder
    {
        return DB::connection('tenant')->transaction(function () use ($order): WorkOrder {
            $locked = $this->lock($order, WorkOrder::IN_PROGRESS, [WorkOrder::PLANNED]);

            try {
                $this->inventory->apply($this->changes($locked, static fn (string $q): array => ['0', $q], 'reserve'), $locked);
            } catch (InsufficientStockException $e) {
                // Refused up front, never discovered mid-production (§64.2).
                throw ApiException::conflict('insufficient_components', 'Not enough of a component is available at this warehouse to start.', $e->details);
            }

            $locked->forceFill(['status' => WorkOrder::IN_PROGRESS, 'started_at' => now()])->save();

            return $locked;
        });
    }

    /**
     * All or nothing: every component leaves its reservation and the
     * finished goods arrive at the recipe's unit cost.
     */
    public function completeWorkOrder(WorkOrder $order): WorkOrder
    {
        return DB::connection('tenant')->transaction(function () use ($order): WorkOrder {
            $locked = $this->lock($order, WorkOrder::COMPLETED, [WorkOrder::IN_PROGRESS]);
            $bom = $locked->bom()->with(['product', 'variant', 'items.component', 'items.componentVariant'])->firstOrFail();
            $unitCost = $this->costs->calculateUnitCost($bom);

            $this->inventory->apply([
                ...$this->changes($locked, static fn (string $q): array => [Quantity::neg($q), Quantity::neg($q)], 'work_order_consume'),
                new StockChange($locked->warehouse, $bom->product, $bom->variant, Quantity::normalize((string) $locked->quantity_to_produce), '0',
                    'work_order_output', 'Work order '.$locked->work_order_number, $unitCost),
            ], $locked);

            WorkOrderMaterial::query()->where('work_order_id', $locked->id)->update(['quantity_consumed' => DB::raw('quantity_required'), 'updated_at' => now()]);
            $locked->forceFill(['status' => WorkOrder::COMPLETED, 'completed_at' => now()])->save();

            if ($bom->is_default) {
                $this->costs->applyToProduct($bom);
            }

            return $locked->load('materials');
        });
    }

    public function cancelWorkOrder(WorkOrder $order): WorkOrder
    {
        return DB::connection('tenant')->transaction(function () use ($order): WorkOrder {
            $locked = $this->lock($order, WorkOrder::CANCELLED, [WorkOrder::PLANNED, WorkOrder::IN_PROGRESS]);

            if ($locked->status === WorkOrder::IN_PROGRESS) {
                $this->inventory->apply($this->changes($locked, static fn (string $q): array => ['0', Quantity::neg($q)], 'release'), $locked);
            }

            $locked->forceFill(['status' => WorkOrder::CANCELLED])->save();

            return $locked;
        });
    }

    /**
     * @param  array{status?: string, warehouse_id?: int, product_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, WorkOrder>
     */
    public function listWorkOrders(array $filters, User $viewer): LengthAwarePaginator
    {
        $query = WorkOrder::query()->with(['bom.product:id,name,sku', 'bom.variant:id,sku', 'warehouse:id,name'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['warehouse_id']), static fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['product_id']), static fn ($q) => $q->whereHas('bom', static fn ($b) => $b->where('product_id', $filters['product_id'])));

        return $this->scope->apply($query, $viewer, 'created_by_user_id', static fn ($q, array $ids) => $q->whereIn('warehouse_id', $ids === [] ? [0] : $ids))
            ->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * A work order the viewer may see (§25.3), else 404.
     */
    public function getWorkOrder(WorkOrder $order, User $viewer): WorkOrder
    {
        $visible = $this->scope->apply(WorkOrder::query()->whereKey($order->id), $viewer, 'created_by_user_id',
            static fn ($q, array $ids) => $q->whereIn('warehouse_id', $ids === [] ? [0] : $ids))->exists();

        return $visible ? $order->load(['bom.product:id,name,sku', 'bom.variant:id,sku', 'warehouse:id,name', 'materials.component:id,name,sku', 'materials.componentVariant:id,sku', 'creator:id,name'])
            : throw new NotFoundHttpException('Not found.');
    }

    /**
     * @param  callable(string): array{0: string, 1: string}  $deltas  quantity → [quantity delta, reserved delta]
     * @return list<StockChange>
     */
    private function changes(WorkOrder $order, callable $deltas, string $type): array
    {
        $order->loadMissing(['warehouse', 'materials.component', 'materials.componentVariant']);
        $changes = [];

        foreach ($order->materials as $material) {
            [$quantity, $reserved] = $deltas(Quantity::normalize((string) $material->quantity_required));
            $changes[] = new StockChange($order->warehouse, $material->component, $material->componentVariant, $quantity, $reserved, $type, 'Work order '.$order->work_order_number);
        }

        return $changes;
    }

    /**
     * @param  list<string>  $from
     */
    private function lock(WorkOrder $order, string $to, array $from): WorkOrder
    {
        /** @var WorkOrder $locked */
        $locked = WorkOrder::query()->lockForUpdate()->findOrFail($order->id);

        if (! in_array($locked->status, $from, true)) {
            throw ApiException::invalidTransition($locked->status, $to);
        }

        return $locked;
    }

    private static function ceil3(string $value): string
    {
        $rounded = bcadd($value, '0', 3);

        return bccomp($rounded, $value, 8) < 0 ? bcadd($rounded, '0.001', 3) : $rounded;
    }

    /**
     * WO-000001, from a locked counter row (never reused).
     */
    private function nextNumber(): string
    {
        $db = DB::connection('tenant');
        $db->table('sequences')->insertOrIgnore(['name' => 'work_order_number', 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $row = $db->table('sequences')->where('name', 'work_order_number')->lockForUpdate()->first();
        $db->table('sequences')->where('name', 'work_order_number')->update(['next_value' => (int) $row->next_value + 1, 'updated_at' => now()]);

        return 'WO-'.str_pad((string) $row->next_value, 6, '0', STR_PAD_LEFT);
    }
}
