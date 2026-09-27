<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One supplier's answer to a quotation request (spec §49.3).
 *
 * @property int $id
 * @property int $quotation_request_id
 * @property int $supplier_id
 * @property string $status
 * @property Carbon|null $valid_until
 * @property string|null $notes
 * @property Carbon|null $received_at
 * @property int|null $purchase_order_id
 * @property-read QuotationRequest $request
 * @property-read Supplier $supplier
 * @property-read Collection<int, SupplierQuotationItem> $items
 */
class SupplierQuotation extends Model
{
    public const string PENDING = 'pending';

    public const string RECEIVED = 'received';

    public const string ACCEPTED = 'accepted';

    public const string REJECTED = 'rejected';

    public const string EXPIRED = 'expired';

    public const array STATUSES = [self::PENDING, self::RECEIVED, self::ACCEPTED, self::REJECTED, self::EXPIRED];

    protected $connection = 'tenant';

    protected $fillable = ['valid_until', 'notes'];

    protected $casts = [
        'quotation_request_id' => 'integer',
        'supplier_id' => 'integer',
        'purchase_order_id' => 'integer',
        'valid_until' => 'date',
        'received_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<QuotationRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(QuotationRequest::class, 'quotation_request_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return HasMany<SupplierQuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierQuotationItem::class)->orderBy('id');
    }
}
