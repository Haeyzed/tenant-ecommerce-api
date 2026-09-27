<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Contracts\Approvable;
use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Approvals\Support\ApprovalGate;
use App\Modules\Catalog\Models\Product;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Support\StockChange;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\PurchaseLines;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders (spec §49.2). Receiving is the only thing that increases
 * stock from a purchase: one atomic stock change per receipt, the accounting
 * request in the same transaction. Submission captures the rate; a matching
 * approval workflow (module_key purchase_order) blocks receiving until it
 * is approved, and a rejection cancels the order.
 */
final readonly class PurchaseOrderService implements Approvable
{
    public const string ENTITY = 'purchase_order';

    private const int LOOKUP_LIMIT = 20;

    public function __construct(
        private InventoryService $inventory,
        private AccountingOutbox $outbox,
        private ApprovalGate $approvals,
        private NotificationDispatchService $notifications,
        private CurrencyService $currencies,
        private TenantSettingsService $settings,
        private CustomFieldService $customFields,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  product_id, product_variant_id?, quantity, unit_cost?
     * @param  array<string, mixed>  $data  currency_code?, order_date?, expected_date?, notes?, custom_fields?
     */
    public function createPurchaseOrder(Supplier $supplier, Warehouse $warehouse, array $items, User $by, array $data = []): PurchaseOrder
    {
        $this->assertUsable($supplier, $warehouse);
        [$validated, $custom] = $this->validateHeader($data, true);
        $lines = PurchaseLines::resolve($items, true, $supplier->id);

        return DB::connection('tenant')->transaction(function () use ($supplier, $warehouse, $validated, $custom, $lines, $by): PurchaseOrder {
            $order = new PurchaseOrder;
            $order->forceFill([
                'po_number' => $this->nextNumber(),
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => PurchaseOrder::DRAFT,
                'currency_code' => $validated['currency_code'] ?? $this->defaultCurrency(),
                'order_date' => $validated['order_date'] ?? now()->toDateString(),
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $by->id,
            ])->save();

            $this->writeLines($order, $lines);
            $this->customFields->save($order, self::ENTITY, $custom);

            return $order;
        });
    }

    /**
     * Draft only: header fields, the destination and (replacing them) the lines.
     *
     * @param  array<string, mixed>  $data  warehouse_id?, currency_code?, order_date?, expected_date?, notes?, items?, custom_fields?
     */
    public function updatePurchaseOrder(PurchaseOrder $order, array $data): PurchaseOrder
    {
        [$validated, $custom] = $this->validateHeader($data, false);
        $lines = isset($data['items']) ? PurchaseLines::resolve((array) $data['items'], true, $order->supplier_id) : null;
        $warehouse = isset($validated['warehouse_id']) ? Warehouse::query()->findOrFail($validated['warehouse_id']) : null;

        if ($warehouse !== null && ! $warehouse->is_active) {
            throw ApiException::unprocessable('warehouse_inactive', 'The warehouse is inactive.');
        }

        DB::connection('tenant')->transaction(function () use ($order, $validated, $custom, $lines, $warehouse): void {
            $locked = $this->lock($order, [PurchaseOrder::DRAFT]);
            $locked->fill(array_intersect_key($validated, array_flip(['order_date', 'expected_date', 'notes'])));

            if (isset($validated['currency_code'])) {
                $locked->currency_code = $validated['currency_code'];
            }

            if ($warehouse !== null) {
                $locked->warehouse_id = $warehouse->id;
            }

            $locked->save();

            if ($lines !== null) {
                $locked->items()->delete();
                $this->writeLines($locked, $lines);
            }

            $this->customFields->save($locked, self::ENTITY, $custom);
            $order->setRawAttributes($locked->getAttributes(), true);
        });

        return $order->unsetRelation('items');
    }

    /**
     * draft → submitted. The rate of a non-base order is captured now
     * (1 order currency = x base); an approval workflow may take over.
     */
    public function submitPurchaseOrder(PurchaseOrder $order): void
    {
        DB::connection('tenant')->transaction(function () use ($order): void {
            $locked = $this->lock($order, [PurchaseOrder::DRAFT]);

            if (! $locked->items()->exists()) {
                throw ApiException::unprocessable('purchase_order_empty', 'Add at least one line before submitting.');
            }

            $rate = $this->currencies->rateFor($locked->currency_code)
                ?? throw ApiException::unprocessable('exchange_rate_unavailable', 'Set an exchange rate for '.$locked->currency_code.' before submitting (Currencies).');

            $locked->forceFill([
                'status' => PurchaseOrder::SUBMITTED,
                'submitted_at' => now(),
                'exchange_rate_used' => $rate === '1' ? '1' : CurrencyService::toBaseRate($rate),
            ])->save();

            $this->approvals->hold('purchase_order', $locked);
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Receives some or all outstanding quantities (§49.2).
     *
     * @param  list<array<string, mixed>>  $receivedItems  purchase_order_item_id, quantity
     */
    public function receiveStock(PurchaseOrder $order, array $receivedItems): void
    {
        Validator::make(['items' => $receivedItems], [
            'items' => ['required', 'array', 'min:1', 'max:'.PurchaseLines::MAX_LINES],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ])->validate();

        $this->approvals->assertNoPending($order);

        DB::connection('tenant')->transaction(function () use ($order, $receivedItems): void {
            $locked = $this->lock($order, [PurchaseOrder::SUBMITTED, PurchaseOrder::PARTIALLY_RECEIVED]);
            $locked->load(['items.product', 'items.variant', 'warehouse', 'supplier']);

            if (! $locked->warehouse->is_active) {
                throw ApiException::unprocessable('warehouse_inactive', 'The destination warehouse is inactive.');
            }

            $rate = $locked->toBaseRate();
            $changes = [];
            $value = Money::normalize(0);
            $errors = [];

            foreach ($receivedItems as $i => $row) {
                /** @var PurchaseOrderItem|null $item */
                $item = $locked->items->firstWhere('id', (int) $row['purchase_order_item_id']);
                $quantity = Quantity::normalize((string) $row['quantity']);

                if ($item === null) {
                    $errors["items.{$i}.purchase_order_item_id"][] = 'This line is not on the purchase order.';

                    continue;
                }

                $outstanding = Quantity::sub((string) $item->quantity_ordered, (string) $item->quantity_received);

                if (Quantity::cmp($quantity, $outstanding) > 0) {
                    $errors["items.{$i}.quantity"][] = 'At most '.$outstanding.' is still to be received on this line.';

                    continue;
                }

                $item->quantity_received = Quantity::add((string) $item->quantity_received, $quantity);
                $item->save();

                $lineValue = Money::mul($quantity, (string) $item->unit_cost);
                $value = Money::add($value, $lineValue);
                $changes[] = new StockChange($locked->warehouse, $item->product, $item->variant, $quantity, Quantity::normalize(0),
                    'purchase_receipt', null, Money::round(Money::mul((string) $item->unit_cost, $rate), $this->currencies->baseCurrency()));
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->inventory->apply($changes, $locked);

            $complete = $locked->items->every(static fn (PurchaseOrderItem $i): bool => Quantity::cmp((string) $i->quantity_received, (string) $i->quantity_ordered) >= 0);
            $locked->forceFill([
                'status' => $complete ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIALLY_RECEIVED,
                'received_at' => $complete ? now() : $locked->received_at,
            ])->save();

            $base = 'po_receipt:'.$locked->id;
            $version = $this->outbox->nextVersion($base);
            $this->outbox->record('postPurchaseOrderReceived', $locked, now(), $version === 1 ? $base : $base.':v'.$version, [
                'value' => Money::round($value, $locked->currency_code),
                'rate' => $rate,
            ]);

            $this->notifications->dispatch('purchase_order.received', $locked, [
                'po_number' => $locked->po_number,
                'supplier_name' => $locked->supplier->name,
            ]);

            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Only draft and submitted orders with nothing received (§49.2).
     */
    public function cancelPurchaseOrder(PurchaseOrder $order): void
    {
        DB::connection('tenant')->transaction(function () use ($order): void {
            $locked = $this->lock($order, [PurchaseOrder::DRAFT, PurchaseOrder::SUBMITTED]);

            if ($locked->items()->where('quantity_received', '>', 0)->exists()) {
                throw ApiException::unprocessable('purchase_order_received', 'Stock was already received on this order.');
            }

            $locked->forceFill(['status' => PurchaseOrder::CANCELLED, 'cancelled_at' => now()])->save();
            $this->approvals->cancel($locked);
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * A new draft with the supplier, warehouse, currency and lines; nothing
     * received, no payments or approvals (§49.2).
     */
    public function duplicatePurchaseOrder(PurchaseOrder $order, User $by): PurchaseOrder
    {
        $order->loadMissing('items');

        return DB::connection('tenant')->transaction(function () use ($order, $by): PurchaseOrder {
            $copy = new PurchaseOrder;
            $copy->forceFill([
                'po_number' => $this->nextNumber(),
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => $order->warehouse_id,
                'status' => PurchaseOrder::DRAFT,
                'currency_code' => $order->currency_code,
                'order_date' => now()->toDateString(),
                'notes' => $order->notes,
                'created_by' => $by->id,
            ])->save();

            foreach ($order->items as $item) {
                $copy->items()->create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity_ordered' => $item->quantity_ordered,
                    'unit_cost' => $item->unit_cost,
                ]);
            }

            return $copy;
        });
    }

    public function getPurchaseOrder(PurchaseOrder $order): PurchaseOrder
    {
        return $order->load(['supplier:id,name,email,phone', 'warehouse:id,name,code', 'creator:id,name', 'items.product:id,name,sku', 'items.variant:id,sku']);
    }

    /**
     * @param  array{status?: string, supplier_id?: int, warehouse_id?: int, from?: string, to?: string, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function listPurchaseOrders(array $filters = []): LengthAwarePaginator
    {
        return PurchaseOrder::query()->with(['supplier:id,name', 'warehouse:id,name', 'items'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['supplier_id']), static fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(isset($filters['warehouse_id']), static fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('order_date', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('order_date', '<=', $filters['to']))
            ->when(isset($filters['search']), static fn ($q) => $q->where('po_number', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%'))
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * The purchase-order product picker (§49.6): simple products and active
     * variants by name or SKU, with on-hand stock when
     * show_product_stock_on_purchase_list is on.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lookupProducts(string $query, ?Warehouse $warehouse = null): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $like = '%'.addcslashes($query, '%_\\').'%';
        $showStock = (bool) $this->settings->get('show_product_stock_on_purchase_list', true);

        $rows = DB::connection('tenant')->table('products as p')
            ->leftJoin('product_variants as v', static fn ($j) => $j->on('v.product_id', '=', 'p.id')
                ->where('p.product_type', '=', Product::VARIABLE)->where('v.is_active', '=', true)->whereNull('v.deleted_at'))
            ->whereNull('p.deleted_at')
            ->where(static fn ($q) => $q->where('p.product_type', Product::SIMPLE)
                ->orWhere(static fn ($v) => $v->where('p.product_type', Product::VARIABLE)->whereNotNull('v.id')))
            ->where(static fn ($q) => $q->where('p.name', 'like', $like)->orWhere('p.sku', $query)->orWhere('v.sku', $query))
            ->when($showStock, static fn ($q) => $q->selectSub(
                DB::connection('tenant')->table('inventory as i')
                    ->whereColumn('i.product_id', 'p.id')->whereRaw('i.variant_key = COALESCE(v.id, 0)')
                    ->when($warehouse !== null, static fn ($s) => $s->where('i.warehouse_id', $warehouse->id))
                    ->selectRaw('COALESCE(SUM(i.quantity), 0)'),
                'on_hand',
            ))
            ->addSelect(['p.id as product_id', 'v.id as product_variant_id', 'p.name', DB::raw('COALESCE(v.sku, p.sku) as sku'), DB::raw('COALESCE(v.cost_price, p.cost_price) as cost_price')])
            ->orderBy('p.name')->orderBy('v.id')
            ->limit(self::LOOKUP_LIMIT)
            ->get();

        return $rows->map(static fn (object $r): array => [
            'product_id' => (int) $r->product_id,
            'product_variant_id' => $r->product_variant_id === null ? null : (int) $r->product_variant_id,
            'name' => $r->name,
            'sku' => $r->sku,
            'cost_price' => $r->cost_price === null ? null : Money::normalize((string) $r->cost_price),
            'on_hand' => $showStock ? Quantity::normalize((string) $r->on_hand) : null,
        ]);
    }

    // ---- Approvals (§60, module_key purchase_order) --------------------

    /**
     * Receiving simply becomes possible: the order stays submitted.
     */
    public function onApprovalGranted(Model $record): void {}

    public function onApprovalRejected(Model $record, ?string $note): void
    {
        PurchaseOrder::query()->whereKey($record->getKey())->where('status', PurchaseOrder::SUBMITTED)
            ->update(['status' => PurchaseOrder::CANCELLED, 'cancelled_at' => now(), 'updated_at' => now()]);
    }

    public function approvalSubject(Model $record): string
    {
        /** @var PurchaseOrder $record */
        return 'purchase order '.$record->po_number;
    }

    /**
     * min_amount is the order total in the base currency.
     */
    public function approvalFacts(Model $record): array
    {
        /** @var PurchaseOrder $record */
        return ['amount' => Money::round(Money::mul($record->total(), $record->toBaseRate()), $this->currencies->baseCurrency())];
    }

    // ---- Internals ------------------------------------------------------------

    /**
     * @param  list<array{product: Product, variant: mixed, quantity: string, unit_cost: string|null}>  $lines
     */
    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            $order->items()->create([
                'product_id' => $line['product']->id,
                'product_variant_id' => $line['variant']?->id,
                'quantity_ordered' => $line['quantity'],
                'unit_cost' => Money::round((string) $line['unit_cost'], $order->currency_code),
            ]);
        }
    }

    /**
     * @param  list<string>  $statuses
     */
    private function lock(PurchaseOrder $order, array $statuses): PurchaseOrder
    {
        /** @var PurchaseOrder $locked */
        $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

        if (! in_array($locked->status, $statuses, true)) {
            throw ApiException::invalidTransition($locked->status, $statuses[0]);
        }

        return $locked;
    }

    private function assertUsable(Supplier $supplier, Warehouse $warehouse): void
    {
        if (! $supplier->is_active || $supplier->trashed()) {
            throw ApiException::unprocessable('supplier_inactive', 'The supplier is inactive.');
        }

        if (! $warehouse->is_active) {
            throw ApiException::unprocessable('warehouse_inactive', 'The warehouse is inactive.');
        }
    }

    private function defaultCurrency(): string
    {
        return strtoupper((string) ($this->settings->get('default_purchase_order_currency') ?: $this->currencies->baseCurrency()));
    }

    /**
     * PO-000001, from a locked counter row (never reused).
     */
    private function nextNumber(): string
    {
        $row = DB::connection('tenant')->table('sequences')->where('name', 'purchase_order_number')->lockForUpdate()->first();

        if ($row === null) {
            DB::connection('tenant')->table('sequences')->insert(['name' => 'purchase_order_number', 'next_value' => 2, 'created_at' => now(), 'updated_at' => now()]);
            $value = 1;
        } else {
            $value = (int) $row->next_value;
            DB::connection('tenant')->table('sequences')->where('name', 'purchase_order_number')->update(['next_value' => $value + 1, 'updated_at' => now()]);
        }

        return 'PO-'.str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validateHeader(array $data, bool $creating): array
    {
        $validator = Validator::make($data, [
            'warehouse_id' => [$creating ? 'prohibited' : 'sometimes', 'integer'],
            'currency_code' => ['sometimes', 'string', 'size:3', Rule::exists('landlord.currencies', 'code')],
            'order_date' => ['sometimes', 'date_format:Y-m-d'],
            'expected_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'custom_fields' => ['sometimes', 'array'],
        ]);

        $errors = $validator->errors()->toArray();
        $custom = [];

        try {
            $custom = $this->customFields->validate(self::ENTITY, (array) ($data['custom_fields'] ?? []), CustomFieldService::ADMIN, $creating);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->errors());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated = $validator->validated();

        if (isset($validated['currency_code'])) {
            $validated['currency_code'] = strtoupper((string) $validated['currency_code']);
        }

        unset($validated['custom_fields']);

        return [$validated, $custom];
    }
}
