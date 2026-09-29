<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The priced answer to a request (spec §53.1): a price commitment, not a
 * stock reservation. One per request.
 *
 * @property int $id
 * @property int $sales_quotation_request_id
 * @property string $quotation_number
 * @property string $status
 * @property string $currency_code
 * @property string $subtotal
 * @property string $discount_amount
 * @property Carbon|null $valid_until
 * @property string|null $notes
 * @property Carbon|null $sent_at
 * @property Carbon|null $responded_at
 * @property int|null $converted_order_id
 * @property int|null $sent_by_user_id
 * @property-read SalesQuotationRequest $request
 * @property-read Collection<int, SalesQuotationItem> $items
 */
class SalesQuotation extends Model implements AuditableContract
{
    use Auditable;

    public const string DRAFT = 'draft';

    public const string SENT = 'sent';

    public const string ACCEPTED = 'accepted';

    public const string REJECTED = 'rejected';

    public const string EXPIRED = 'expired';

    public const array STATUSES = [self::DRAFT, self::SENT, self::ACCEPTED, self::REJECTED, self::EXPIRED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'sales_quotation_request_id' => 'integer',
        'subtotal' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'converted_order_id' => 'integer',
        'sent_by_user_id' => 'integer',
    ];

    /**
     * Before tax: tax is calculated when the quote becomes an order (§53.2).
     */
    public function total(): string
    {
        return bcsub((string) $this->subtotal, (string) $this->discount_amount, 4);
    }

    /**
     * @return BelongsTo<SalesQuotationRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SalesQuotationRequest::class, 'sales_quotation_request_id');
    }

    /**
     * @return HasMany<SalesQuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesQuotationItem::class)->orderBy('id');
    }
}
