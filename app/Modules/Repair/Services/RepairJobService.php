<?php

declare(strict_types=1);

namespace App\Modules\Repair\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Cart\Services\PricingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Repair\Models\RepairJob;
use App\Modules\Repair\Models\RepairJobLabor;
use App\Modules\Repair\Models\RepairJobPart;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Modules\Users\Support\StaffAccessScope;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Repair jobs (spec §67). Parts leave the job's warehouse when fitted, and
 * only in in_repair, so nothing is consumed before an estimate is
 * approved; cancelling puts them back. The invoice is an admin order
 * whose part lines are marked stock_already_deducted and whose labour
 * lines have no product and no tax (A-58). Once invoiced, parts and
 * labour are locked.
 */
final readonly class RepairJobService
{
    /** Staff status moves (§67.2); cancellation and pickup are handled apart. */
    private const array TRANSITIONS = [
        RepairJob::RECEIVED => [RepairJob::DIAGNOSING],
        RepairJob::DIAGNOSING => [RepairJob::IN_REPAIR],
        RepairJob::AWAITING_APPROVAL => [RepairJob::IN_REPAIR],
        RepairJob::IN_REPAIR => [RepairJob::COMPLETED],
    ];

    public function __construct(
        private InventoryService $inventory,
        private OrderService $orders,
        private PricingService $pricing,
        private TaxService $tax,
        private CurrencyService $currencies,
        private TenantSettingsService $settings,
        private NotificationDispatchService $notifications,
        private FeatureAccessService $features,
        private StaffAccessScope $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $data  customer_id? | customer_name, customer_phone?, booking_id?, item_description, warehouse_id
     */
    public function createJob(array $data, User $by): RepairJob
    {
        $validated = Validator::make($data, [
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.customers', 'id')->whereNull('deleted_at')],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:120'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'booking_id' => ['sometimes', 'nullable', 'integer'],
            'item_description' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['required', 'integer'],
        ])->validate();

        $warehouse = Warehouse::query()->whereKey($validated['warehouse_id'])->where('is_active', true)->first()
            ?? throw ApiException::unprocessable('warehouse_inactive', 'Choose an active location.');
        $customer = isset($validated['customer_id']) ? Customer::query()->find($validated['customer_id']) : null;
        $booking = null;

        // The drop-off booking, when booking is on (§67).
        if (isset($validated['booking_id'])) {
            $tenant = tenant();

            if (! $tenant instanceof Tenant || $this->features->state($tenant, 'booking') !== ModuleState::Enabled) {
                throw ApiException::unprocessable('booking_unavailable', 'Bookings are not enabled.');
            }

            $booking = Booking::query()->find($validated['booking_id']) ?? throw ApiException::unprocessable('booking_invalid', 'This booking does not exist.');

            if ($customer !== null && $booking->customer_id !== null && $booking->customer_id !== $customer->id) {
                throw ApiException::unprocessable('booking_invalid', 'This booking belongs to another customer.');
            }
        }

        $job = new RepairJob;
        $job->forceFill([
            'customer_id' => $customer?->id ?? $booking?->customer_id,
            'customer_name' => $validated['customer_name'] ?? $customer?->name ?? $booking?->guest_name,
            'customer_phone' => $validated['customer_phone'] ?? $customer?->phone ?? $booking?->guest_phone,
            'booking_id' => $booking?->id,
            'item_description' => $validated['item_description'],
            'warehouse_id' => $warehouse->id,
            'status' => RepairJob::RECEIVED,
            'created_by_user_id' => $by->id,
            'received_at' => now(),
        ])->save();

        return $this->getJob($job);
    }

    /**
     * With an estimate the job waits for the customer's approval and they
     * are told; without one it stays in diagnosis and may go straight to
     * repair.
     */
    public function updateDiagnosis(RepairJob $job, string $notes, ?string $estimatedCost): RepairJob
    {
        Validator::make(['notes' => $notes, 'estimated_cost' => $estimatedCost], [
            'notes' => ['required', 'string', 'max:10000'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999'],
        ])->validate();

        $locked = DB::connection('tenant')->transaction(function () use ($job, $notes, $estimatedCost): RepairJob {
            $locked = $this->lock($job, [RepairJob::DIAGNOSING], RepairJob::AWAITING_APPROVAL);
            $locked->forceFill([
                'diagnosis_notes' => $notes,
                'estimated_cost' => $estimatedCost === null ? null : Money::normalize($estimatedCost),
                'status' => $estimatedCost === null ? RepairJob::DIAGNOSING : RepairJob::AWAITING_APPROVAL,
                'customer_approved_at' => null,
            ])->save();

            return $locked;
        });

        if ($locked->status === RepairJob::AWAITING_APPROVAL) {
            $this->notifications->dispatch('repair_job.diagnosis_ready', $locked, [
                'customer_name' => (string) ($locked->customer_name ?? ''),
                'job_number' => self::number($locked),
                'diagnosis' => $notes,
                'estimated_cost' => Money::format((string) $locked->estimated_cost, $this->currencies->baseCurrency()),
            ]);
        }

        return $this->getJob($locked);
    }

    /**
     * The customer's go-ahead on the estimate: through their account, or
     * recorded by staff (a phone call).
     */
    public function recordCustomerApproval(RepairJob $job): RepairJob
    {
        return DB::connection('tenant')->transaction(function () use ($job): RepairJob {
            $locked = $this->lock($job, [RepairJob::AWAITING_APPROVAL], 'approved');
            $locked->forceFill(['customer_approved_at' => $locked->customer_approved_at ?? now()])->save();

            return $this->getJob($locked);
        });
    }

    public function updateStatus(RepairJob $job, string $status): RepairJob
    {
        if ($status === RepairJob::CANCELLED) {
            return $this->cancel($job);
        }

        if ($status === RepairJob::PICKED_UP) {
            return $this->markPickedUp($job);
        }

        $locked = DB::connection('tenant')->transaction(function () use ($job, $status): RepairJob {
            /** @var RepairJob $locked */
            $locked = RepairJob::query()->lockForUpdate()->findOrFail($job->id);

            if (! in_array($status, self::TRANSITIONS[$locked->status] ?? [], true)) {
                throw ApiException::invalidTransition($locked->status, $status);
            }

            if ($status === RepairJob::IN_REPAIR && $locked->status === RepairJob::DIAGNOSING && $locked->estimated_cost !== null) {
                throw ApiException::unprocessable('approval_required', 'This job has an estimate: it needs the customer\'s approval first.');
            }

            if ($status === RepairJob::IN_REPAIR && $locked->status === RepairJob::AWAITING_APPROVAL && $locked->customer_approved_at === null) {
                throw ApiException::unprocessable('approval_required', 'The customer has not approved the estimate yet.');
            }

            $locked->forceFill(['status' => $status, ...($status === RepairJob::COMPLETED ? ['completed_at' => now()] : [])])->save();

            return $locked;
        });

        if ($status === RepairJob::COMPLETED) {
            $this->notifications->dispatch('repair_job.ready_for_pickup', $locked, [
                'customer_name' => (string) ($locked->customer_name ?? ''),
                'job_number' => self::number($locked),
            ]);
        }

        return $this->getJob($locked);
    }

    public function markPickedUp(RepairJob $job): RepairJob
    {
        return DB::connection('tenant')->transaction(function () use ($job): RepairJob {
            $locked = $this->lock($job, [RepairJob::COMPLETED], RepairJob::PICKED_UP);
            $locked->forceFill(['status' => RepairJob::PICKED_UP, 'picked_up_at' => now()])->save();

            return $this->getJob($locked);
        });
    }

    /**
     * Fitted in in_repair only; the part leaves the job's warehouse at once
     * at its current cost (§67.3).
     */
    public function addPart(RepairJob $job, Product $product, ?ProductVariant $variant, string $quantity): RepairJobPart
    {
        Validator::make(['quantity' => $quantity], ['quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:100000']])->validate();

        if (! in_array($product->product_type, [Product::SIMPLE, Product::VARIABLE], true) || $product->trashed()
            || ($product->product_type === Product::VARIABLE) !== ($variant !== null) || ($variant !== null && $variant->product_id !== $product->id)) {
            throw ApiException::unprocessable('part_invalid', 'A part is a stocked product (with its variant when it has variants).');
        }

        return DB::connection('tenant')->transaction(function () use ($job, $product, $variant, $quantity): RepairJobPart {
            $locked = $this->lockEditable($job, [RepairJob::IN_REPAIR]);
            $locked->loadMissing('warehouse');
            $quantity = Quantity::normalize($quantity);

            $part = new RepairJobPart;
            $part->forceFill([
                'repair_job_id' => $locked->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'unit_cost_snapshot' => $variant?->cost_price ?? $product->cost_price,
            ])->save();

            $this->inventory->adjustStock($locked->warehouse, $product, $variant, Quantity::neg($quantity), 'repair_consume', $locked, 'repair_part');

            return $part->load(['product:id,name,sku', 'variant:id,sku']);
        });
    }

    public function removePart(RepairJob $job, RepairJobPart $part): void
    {
        DB::connection('tenant')->transaction(function () use ($job, $part): void {
            $locked = $this->lockEditable($job, [RepairJob::IN_REPAIR]);
            $this->restock($locked, $part);
            $part->delete();
        });
    }

    public function addLabor(RepairJob $job, string $description, string $amount): RepairJobLabor
    {
        Validator::make(['description' => $description, 'amount' => $amount], [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($job, $description, $amount): RepairJobLabor {
            $locked = $this->lockEditable($job, [RepairJob::DIAGNOSING, RepairJob::IN_REPAIR]);
            $labor = new RepairJobLabor;
            $labor->forceFill(['repair_job_id' => $locked->id, 'description' => $description, 'amount' => Money::normalize($amount)])->save();

            return $labor;
        });
    }

    public function removeLabor(RepairJob $job, RepairJobLabor $labor): void
    {
        DB::connection('tenant')->transaction(function () use ($job, $labor): void {
            $this->lockEditable($job, [RepairJob::DIAGNOSING, RepairJob::IN_REPAIR]);
            $labor->delete();
        });
    }

    /**
     * Once, in in_repair or completed (§67.3): part lines at the resolved
     * price at the job's warehouse, taxed there, never moving stock again;
     * labour lines untaxed and without a product.
     */
    public function generateInvoice(RepairJob $job, User $by): Order
    {
        return DB::connection('tenant')->transaction(function () use ($job, $by): Order {
            $locked = $this->lock($job, [RepairJob::IN_REPAIR, RepairJob::COMPLETED], 'invoiced');

            if ($locked->order_id !== null) {
                throw ApiException::conflict('repair_job_already_invoiced', 'This job has been invoiced already.', ['order_id' => $locked->order_id]);
            }

            $locked->load(['warehouse', 'customer', 'parts.product', 'parts.variant', 'labor']);

            if ($locked->parts->isEmpty() && $locked->labor->isEmpty()) {
                throw ApiException::unprocessable('repair_job_empty', 'Add the parts or labour to bill first.');
            }

            $currency = $this->currencies->baseCurrency();
            $inclusive = (bool) $this->settings->get('prices_include_tax', false);
            $warehouse = $locked->warehouse;
            $lines = [];

            foreach ($locked->parts as $part) {
                $price = $this->pricing->resolveUnitPrice($part->product, $part->variant, $warehouse, $currency, '1');
                $lines[] = [
                    'product' => $part->product, 'variant' => $part->variant, 'warehouse' => $warehouse, 'quantity' => (string) $part->quantity,
                    'unit_price' => $price->unitPrice, 'price_source' => $price->source, 'unit_cost' => $part->unit_cost_snapshot,
                    'subtotal' => Money::round(bcmul($price->unitPrice, (string) $part->quantity, 10), $currency),
                    'stock_already_deducted' => true,
                ];
            }

            $address = $warehouse->country_id === null ? null : ['country_id' => $warehouse->country_id, 'state_id' => $warehouse->state_id, 'address_id' => null];
            $taxed = $address === null || $lines === [] ? null : $this->tax->calculateForLines(array_map(static fn (array $l): array => [
                'amount' => $l['subtotal'], 'tax_class' => (string) ($l['product']->tax_class ?: 'standard'), 'origin' => $warehouse,
            ], $lines), $address, $warehouse, '0', $currency);
            $subtotal = $tax = Money::normalize(0);

            foreach ($lines as $i => $line) {
                $lineTax = $taxed['lines'][$i]['tax_amount'] ?? Money::normalize(0);
                $lines[$i] = [...$line, 'tax_rate_applied' => $taxed['lines'][$i]['tax_rate_applied'] ?? '0', 'tax_amount' => $lineTax,
                    'tax_breakdown' => $taxed['lines'][$i]['tax_breakdown'] ?? null, 'line_total' => $inclusive ? $line['subtotal'] : Money::add($line['subtotal'], $lineTax)];
                unset($lines[$i]['subtotal']);
                $subtotal = Money::add($subtotal, $line['subtotal']);
                $tax = Money::add($tax, $lineTax);
            }

            foreach ($locked->labor as $labor) {
                $amount = Money::normalize((string) $labor->amount);
                $lines[] = ['product' => null, 'name' => $labor->description, 'quantity' => '1', 'unit_price' => $amount, 'price_source' => 'labor', 'line_total' => $amount];
                $subtotal = Money::add($subtotal, $amount);
            }

            $order = $this->orders->createOrder([
                'order_source' => 'admin',
                'status' => Order::PENDING,
                'customer' => $locked->customer,
                'customer_name' => $locked->customer?->name ?? $locked->customer_name,
                'customer_phone' => $locked->customer?->phone ?? $locked->customer_phone,
                'currency_code' => $currency,
                'exchange_rate' => '1',
                'prices_include_tax' => $inclusive,
                'lines' => $lines,
                'totals' => ['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $inclusive ? $subtotal : Money::add($subtotal, $tax)],
                'created_by_user_id' => $by->id,
                'customer_note' => 'Repair '.self::number($locked).': '.$locked->item_description,
            ]);

            $locked->forceFill(['order_id' => $order->id])->save();

            return $order;
        });
    }

    /**
     * @param  array{status?: string, customer_id?: int, warehouse_id?: int, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, RepairJob>
     */
    public function listJobs(array $filters, User $viewer): LengthAwarePaginator
    {
        $query = RepairJob::query()->with(['warehouse:id,name', 'customer:id,name'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['customer_id']), static fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['warehouse_id']), static fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('received_at', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('received_at', '<=', $filters['to']));

        return $this->scope->apply($query, $viewer, 'created_by_user_id', static fn ($q, array $ids) => $q->whereIn('warehouse_id', $ids === [] ? [0] : $ids))
            ->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @return Collection<int, RepairJob>
     */
    public function listForCustomer(Customer $customer): Collection
    {
        return RepairJob::query()->with(['warehouse:id,name'])->where('customer_id', $customer->id)->orderByDesc('id')->limit(200)->get();
    }

    public function getJob(RepairJob $job, ?User $viewer = null): RepairJob
    {
        if ($viewer !== null && ! $this->scope->apply(RepairJob::query()->whereKey($job->id), $viewer, 'created_by_user_id',
            static fn ($q, array $ids) => $q->whereIn('warehouse_id', $ids === [] ? [0] : $ids))->exists()) {
            throw new NotFoundHttpException('Not found.');
        }

        return $job->load(['warehouse:id,name', 'customer:id,name,email,phone', 'parts.product:id,name,sku', 'parts.variant:id,sku', 'labor',
            'order:id,order_number,status,payment_status,total,currency_code']);
    }

    public static function number(RepairJob $job): string
    {
        return 'RJ-'.str_pad((string) $job->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Any time before completion (§67.2): every part goes back on the shelf
     * and an unpaid invoice is cancelled with the job.
     */
    private function cancel(RepairJob $job): RepairJob
    {
        $locked = DB::connection('tenant')->transaction(function () use ($job): RepairJob {
            $locked = $this->lock($job, [RepairJob::RECEIVED, RepairJob::DIAGNOSING, RepairJob::AWAITING_APPROVAL, RepairJob::IN_REPAIR], RepairJob::CANCELLED);
            $order = $locked->order_id === null ? null : Order::query()->find($locked->order_id);

            if ($order !== null && ($order->payment_status !== 'unpaid' || $order->confirmed_at !== null)) {
                throw ApiException::unprocessable('repair_job_paid', 'This job\'s invoice has payments. Refund it first.');
            }

            foreach ($locked->parts()->get() as $part) {
                $this->restock($locked, $part);
            }

            $locked->forceFill(['status' => RepairJob::CANCELLED])->save();

            return $locked;
        });

        $order = $locked->order_id === null ? null : Order::query()->find($locked->order_id);

        if ($order !== null && $order->status === Order::PENDING) {
            $this->orders->cancelOrder($order, 'Repair cancelled');
        }

        return $this->getJob($locked);
    }

    private function restock(RepairJob $job, RepairJobPart $part): void
    {
        $job->loadMissing('warehouse');
        $part->loadMissing(['product', 'variant']);
        $this->inventory->adjustStock($job->warehouse, $part->product, $part->variant, Quantity::normalize((string) $part->quantity), 'repair_return', $job, 'repair_part', $part->unit_cost_snapshot === null ? null : (string) $part->unit_cost_snapshot);
    }

    /**
     * @param  list<string>  $from
     */
    private function lockEditable(RepairJob $job, array $from): RepairJob
    {
        $locked = $this->lock($job, $from, $from[count($from) - 1]);

        if ($locked->order_id !== null) {
            throw ApiException::unprocessable('repair_job_invoiced', 'This job has been invoiced: parts and labour are locked.');
        }

        return $locked;
    }

    /**
     * @param  list<string>  $from
     */
    private function lock(RepairJob $job, array $from, string $to): RepairJob
    {
        /** @var RepairJob $locked */
        $locked = RepairJob::query()->lockForUpdate()->findOrFail($job->id);

        if (! in_array($locked->status, $from, true)) {
            throw ApiException::invalidTransition($locked->status, $to);
        }

        return $locked;
    }
}
