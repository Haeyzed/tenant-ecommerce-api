<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Inventory\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request for pricing sent to several suppliers (spec §49.3).
 *
 * @property int $id
 * @property int $warehouse_id
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $respond_by
 * @property Carbon $requested_at
 * @property int $created_by
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, QuotationRequestItem> $items
 * @property-read Collection<int, SupplierQuotation> $quotations
 */
class QuotationRequest extends Model
{
    public const string DRAFT = 'draft';

    public const string SENT = 'sent';

    public const string RECEIVED = 'received';

    public const string CONVERTED = 'converted';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::DRAFT, self::SENT, self::RECEIVED, self::CONVERTED, self::CANCELLED];

    protected $connection = 'tenant';

    protected $fillable = ['notes', 'respond_by'];

    protected $casts = ['warehouse_id' => 'integer', 'created_by' => 'integer', 'respond_by' => 'date', 'requested_at' => 'datetime'];

    public function number(): string
    {
        return 'RFQ-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<QuotationRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationRequestItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<SupplierQuotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class)->orderBy('id');
    }
}
