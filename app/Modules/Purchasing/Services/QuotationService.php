<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\QuotationRequest;
use App\Modules\Purchasing\Models\QuotationRequestItem;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierQuotation;
use App\Modules\Purchasing\Models\SupplierQuotationItem;
use App\Modules\Purchasing\Support\PurchaseLines;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Requests for pricing before a purchase order (spec §49.3). Suppliers
 * answer by email; staff record each answer; accepting one creates the
 * draft purchase order and rejects the others.
 */
final readonly class QuotationService
{
    public function __construct(
        private PurchaseOrderService $orders,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  product_id, product_variant_id?, quantity
     * @param  array<string, mixed>  $data  notes?, respond_by?
     */
    public function createRequest(Warehouse $warehouse, array $items, User $by, array $data = []): QuotationRequest
    {
        $validated = Validator::make($data, [
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'respond_by' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ])->validate();

        if (! $warehouse->is_active) {
            throw ApiException::unprocessable('warehouse_inactive', 'The warehouse is inactive.');
        }

        $lines = PurchaseLines::resolve(array_map(static fn (array $i): array => array_diff_key($i, ['unit_cost' => true]), $items), false);

        return DB::connection('tenant')->transaction(function () use ($warehouse, $validated, $lines, $by): QuotationRequest {
            $request = new QuotationRequest($validated);
            $request->forceFill(['warehouse_id' => $warehouse->id, 'status' => QuotationRequest::DRAFT, 'requested_at' => now(), 'created_by' => $by->id])->save();

            foreach ($lines as $line) {
                $request->items()->create(['product_id' => $line['product']->id, 'product_variant_id' => $line['variant']?->id, 'quantity_requested' => $line['quantity']]);
            }

            return $request;
        });
    }

    /**
     * One pending quotation per supplier and an email to each (§49.3). A
     * sent request can go to more suppliers; ones already asked are skipped.
     *
     * @param  list<int>  $supplierIds
     */
    public function sendToSuppliers(QuotationRequest $request, array $supplierIds): void
    {
        Validator::make(['supplier_ids' => $supplierIds], [
            'supplier_ids' => ['required', 'array', 'min:1', 'max:50'],
            'supplier_ids.*' => ['integer', 'distinct'],
        ])->validate();

        $suppliers = Supplier::query()->whereKey($supplierIds)->where('is_active', true)->get();

        if ($suppliers->count() !== count($supplierIds)) {
            throw ValidationException::withMessages(['supplier_ids' => ['Choose active suppliers.']]);
        }

        $created = DB::connection('tenant')->transaction(function () use ($request, $suppliers): array {
            $locked = $this->lock($request, [QuotationRequest::DRAFT, QuotationRequest::SENT, QuotationRequest::RECEIVED]);
            $asked = $locked->quotations()->pluck('supplier_id')->all();
            $created = [];

            foreach ($suppliers as $supplier) {
                if (in_array($supplier->id, $asked, true)) {
                    continue;
                }

                $quotation = new SupplierQuotation;
                $quotation->forceFill(['quotation_request_id' => $locked->id, 'supplier_id' => $supplier->id, 'status' => SupplierQuotation::PENDING])->save();
                $created[] = $supplier;
            }

            if ($locked->status === QuotationRequest::DRAFT) {
                $locked->forceFill(['status' => QuotationRequest::SENT])->save();
            }

            $request->setRawAttributes($locked->getAttributes(), true);

            return $created;
        });

        $request->load('items.product:id,name', 'items.variant:id,sku');
        $items = $request->items->map(static fn (QuotationRequestItem $i): string => '- '.$i->product->name.($i->variant === null ? '' : ' ('.$i->variant->sku.')').' × '.rtrim(rtrim((string) $i->quantity_requested, '0'), '.'))->implode("\n");

        foreach ($created as $supplier) {
            $this->notifications->dispatch('quotation_request.sent', $supplier, [
                'supplier_name' => $supplier->contact_name ?? $supplier->name,
                'request_number' => $request->number(),
                'respond_by' => $request->respond_by?->toFormattedDateString() ?? 'your earliest convenience',
                'items' => $items,
            ]);
        }
    }

    /**
     * Staff enter a supplier's prices (§49.3). The request becomes received
     * with the first recorded quote.
     *
     * @param  list<array<string, mixed>>  $items  quotation_request_item_id, unit_price, lead_time_days?
     */
    public function recordSupplierQuote(SupplierQuotation $quotation, array $items, ?Carbon $validUntil = null, ?string $notes = null): void
    {
        Validator::make(['items' => $items, 'notes' => $notes], [
            'items' => ['required', 'array', 'min:1', 'max:'.PurchaseLines::MAX_LINES],
            'items.*.quotation_request_item_id' => ['required', 'integer', 'distinct'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
            'items.*.lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        DB::connection('tenant')->transaction(function () use ($quotation, $items, $validUntil, $notes): void {
            /** @var SupplierQuotation $locked */
            $locked = SupplierQuotation::query()->lockForUpdate()->findOrFail($quotation->id);

            if (! in_array($locked->status, [SupplierQuotation::PENDING, SupplierQuotation::RECEIVED], true)) {
                throw ApiException::invalidTransition($locked->status, SupplierQuotation::RECEIVED);
            }

            $request = $this->lock($locked->request, [QuotationRequest::SENT, QuotationRequest::RECEIVED]);
            $lineIds = $request->items()->pluck('id')->all();

            foreach ($items as $i => $row) {
                if (! in_array((int) $row['quotation_request_item_id'], $lineIds, true)) {
                    throw ValidationException::withMessages(["items.{$i}.quotation_request_item_id" => ['This line is not on the request.']]);
                }

                SupplierQuotationItem::query()->updateOrCreate(
                    ['supplier_quotation_id' => $locked->id, 'quotation_request_item_id' => (int) $row['quotation_request_item_id']],
                    ['unit_price' => Money::normalize((string) $row['unit_price']), 'lead_time_days' => $row['lead_time_days'] ?? null],
                );
            }

            $locked->forceFill([
                'status' => SupplierQuotation::RECEIVED,
                'received_at' => now(),
                'valid_until' => $validUntil?->toDateString() ?? $locked->valid_until,
                'notes' => $notes ?? $locked->notes,
            ])->save();

            if ($request->status === QuotationRequest::SENT) {
                $request->forceFill(['status' => QuotationRequest::RECEIVED])->save();
            }

            $quotation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * A draft purchase order from the quote: supplier, warehouse and every
     * line with its quoted price; siblings are rejected, the request is
     * converted (§49.3).
     */
    public function acceptQuotation(SupplierQuotation $quotation, User $by): PurchaseOrder
    {
        return DB::connection('tenant')->transaction(function () use ($quotation, $by): PurchaseOrder {
            /** @var SupplierQuotation $locked */
            $locked = SupplierQuotation::query()->lockForUpdate()->with(['items', 'supplier'])->findOrFail($quotation->id);

            if ($locked->status !== SupplierQuotation::RECEIVED) {
                throw ApiException::invalidTransition($locked->status, SupplierQuotation::ACCEPTED);
            }

            if ($locked->valid_until !== null && $locked->valid_until->lt(today())) {
                throw ApiException::unprocessable('quotation_expired', 'This quotation is no longer valid.');
            }

            $request = $this->lock($locked->request, [QuotationRequest::RECEIVED]);
            $request->load(['items', 'warehouse']);
            $prices = $locked->items->keyBy('quotation_request_item_id');

            if ($request->items->contains(static fn (QuotationRequestItem $i): bool => ! $prices->has($i->id))) {
                throw ApiException::unprocessable('quotation_incomplete', 'Record this supplier\'s price for every line before accepting.');
            }

            $order = $this->orders->createPurchaseOrder($locked->supplier, $request->warehouse, $request->items->map(static fn (QuotationRequestItem $i): array => [
                'product_id' => $i->product_id,
                'product_variant_id' => $i->product_variant_id,
                'quantity' => (string) $i->quantity_requested,
                'unit_cost' => (string) $prices->get($i->id)->unit_price,
            ])->all(), $by, ['notes' => 'From quotation '.$request->number()]);

            $locked->forceFill(['status' => SupplierQuotation::ACCEPTED, 'purchase_order_id' => $order->id])->save();
            SupplierQuotation::query()->where('quotation_request_id', $request->id)->whereKeyNot($locked->id)
                ->whereIn('status', [SupplierQuotation::PENDING, SupplierQuotation::RECEIVED])
                ->update(['status' => SupplierQuotation::REJECTED, 'updated_at' => now()]);
            $request->forceFill(['status' => QuotationRequest::CONVERTED])->save();

            $quotation->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    public function rejectQuotation(SupplierQuotation $quotation): void
    {
        $updated = SupplierQuotation::query()->whereKey($quotation->id)->whereIn('status', [SupplierQuotation::PENDING, SupplierQuotation::RECEIVED])
            ->update(['status' => SupplierQuotation::REJECTED, 'updated_at' => now()]);

        if ($updated === 0) {
            throw ApiException::invalidTransition((string) $quotation->fresh()?->status, SupplierQuotation::REJECTED);
        }

        $quotation->refresh();
    }

    public function cancelRequest(QuotationRequest $request): void
    {
        DB::connection('tenant')->transaction(function () use ($request): void {
            $locked = $this->lock($request, [QuotationRequest::DRAFT, QuotationRequest::SENT, QuotationRequest::RECEIVED]);
            $locked->forceFill(['status' => QuotationRequest::CANCELLED])->save();
            $locked->quotations()->whereIn('status', [SupplierQuotation::PENDING, SupplierQuotation::RECEIVED])
                ->update(['status' => SupplierQuotation::REJECTED, 'updated_at' => now()]);
            $request->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * ExpireSupplierQuotations (§49.3): pending and received quotes past
     * valid_until.
     */
    public function expireQuotations(): int
    {
        return SupplierQuotation::query()->whereIn('status', [SupplierQuotation::PENDING, SupplierQuotation::RECEIVED])
            ->whereNotNull('valid_until')->whereDate('valid_until', '<', today())
            ->update(['status' => SupplierQuotation::EXPIRED, 'updated_at' => now()]);
    }

    public function getRequest(QuotationRequest $request): QuotationRequest
    {
        return $request->load(['warehouse:id,name', 'items.product:id,name,sku', 'items.variant:id,sku', 'quotations.supplier:id,name', 'quotations.items']);
    }

    /**
     * @param  array{status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, QuotationRequest>
     */
    public function listRequests(array $filters = []): LengthAwarePaginator
    {
        return QuotationRequest::query()->with(['warehouse:id,name'])->withCount(['items', 'quotations'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('requested_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @param  list<string>  $statuses
     */
    private function lock(QuotationRequest $request, array $statuses): QuotationRequest
    {
        /** @var QuotationRequest $locked */
        $locked = QuotationRequest::query()->lockForUpdate()->findOrFail($request->id);

        if (! in_array($locked->status, $statuses, true)) {
            throw ApiException::invalidTransition($locked->status, $statuses[0]);
        }

        return $locked;
    }
}
