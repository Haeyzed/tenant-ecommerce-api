<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Inventory\Models\Warehouse;
use App\Shared\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Received stock going back to its supplier (spec §49.5). Stock leaves and
 * payables fall on approval; resolution settles the money.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property int $purchase_return_reason_id
 * @property string $status
 * @property string|null $resolution refund | credit_note
 * @property string|null $note
 * @property string|null $rejection_reason
 * @property Carbon $requested_at
 * @property Carbon|null $resolved_at
 * @property int $created_by
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Supplier $supplier
 * @property-read Warehouse $warehouse
 * @property-read PurchaseReturnReason $reason
 * @property-read Collection<int, PurchaseReturnItem> $items
 */
class PurchaseReturn extends Model implements AuditableContract
{
    use Auditable;

    public const string REQUESTED = 'requested';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string SHIPPED_BACK = 'shipped_back';

    public const string REFUNDED = 'refunded';

    public const string CLOSED = 'closed';

    public const array STATUSES = [self::REQUESTED, self::APPROVED, self::REJECTED, self::SHIPPED_BACK, self::REFUNDED, self::CLOSED];

    /** States in which the returned value counts against the order balance. */
    public const array APPROVED_STATES = [self::APPROVED, self::SHIPPED_BACK, self::REFUNDED, self::CLOSED];

    public const array RESOLUTIONS = ['refund', 'credit_note'];

    protected $connection = 'tenant';

    protected $fillable = ['note'];

    protected $casts = [
        'purchase_order_id' => 'integer',
        'supplier_id' => 'integer',
        'warehouse_id' => 'integer',
        'purchase_return_reason_id' => 'integer',
        'created_by' => 'integer',
        'requested_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Σ quantity × unit_cost, in the purchase-order currency.
     */
    public function value(string $currency): string
    {
        $this->loadMissing('items');

        return Money::round($this->items->reduce(
            static fn (string $sum, PurchaseReturnItem $i): string => Money::add($sum, Money::mul((string) $i->quantity, (string) $i->unit_cost)),
            Money::normalize(0),
        ), $currency);
    }

    public function number(): string
    {
        return 'PR-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
     * @return BelongsTo<PurchaseReturnReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnReason::class, 'purchase_return_reason_id');
    }

    /**
     * @return HasMany<PurchaseReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class)->orderBy('id');
    }
}
