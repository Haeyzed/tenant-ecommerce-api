<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quoted line: the unit price and an optional line discount for one
 * requested line (spec §53.1).
 *
 * @property int $id
 * @property int $sales_quotation_id
 * @property int $sales_quotation_request_item_id
 * @property string $unit_price
 * @property string|null $discount_amount
 * @property-read SalesQuotationRequestItem $requestItem
 */
class SalesQuotationItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'sales_quotation_id' => 'integer',
        'sales_quotation_request_item_id' => 'integer',
        'unit_price' => 'decimal:4',
        'discount_amount' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<SalesQuotationRequestItem, $this>
     */
    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(SalesQuotationRequestItem::class, 'sales_quotation_request_item_id');
    }
}
