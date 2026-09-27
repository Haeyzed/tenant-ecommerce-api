<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Users\Models\User;
use App\Shared\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A purchase from a supplier into one warehouse (spec §49.2). Receiving it
 * is what increases stock. exchange_rate_used (1 order currency = x base)
 * is captured on submit.
 *
 * @property int $id
 * @property string $po_number
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property string $status
 * @property string $currency_code
 * @property string|null $exchange_rate_used
 * @property Carbon $order_date
 * @property Carbon|null $expected_date
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $submitted_at
 * @property Carbon|null $received_at
 * @property Carbon|null $cancelled_at
 * @property-read Supplier $supplier
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, PurchaseOrderItem> $items
 */
class PurchaseOrder extends Model implements AuditableContract
{
    use Auditable;

    public const string DRAFT = 'draft';

    public const string SUBMITTED = 'submitted';

    public const string PARTIALLY_RECEIVED = 'partially_received';

    public const string RECEIVED = 'received';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::DRAFT, self::SUBMITTED, self::PARTIALLY_RECEIVED, self::RECEIVED, self::CANCELLED];

    protected $connection = 'tenant';

    protected $fillable = ['expected_date', 'notes', 'order_date'];

    protected $casts = [
        'supplier_id' => 'integer',
        'warehouse_id' => 'integer',
        'created_by' => 'integer',
        'exchange_rate_used' => 'decimal:12',
        'order_date' => 'date',
        'expected_date' => 'date',
        'submitted_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Σ quantity_ordered × unit_cost, in the order currency (computed, never
     * stored, §49.2).
     */
    public function total(): string
    {
        $this->loadMissing('items');

        return Money::round($this->items->reduce(
            static fn (string $sum, PurchaseOrderItem $i): string => Money::add($sum, Money::mul((string) $i->quantity_ordered, (string) $i->unit_cost)),
            Money::normalize(0),
        ), $this->currency_code);
    }

    /**
     * The multiplier into the base currency (1 before submission of a
     * base-currency order).
     */
    public function toBaseRate(): string
    {
        return $this->exchange_rate_used === null ? '1' : (string) $this->exchange_rate_used;
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }
}
