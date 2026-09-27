<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_quotation_id
 * @property int $quotation_request_item_id
 * @property string $unit_price
 * @property int|null $lead_time_days
 * @property-read QuotationRequestItem $requestItem
 */
class SupplierQuotationItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['supplier_quotation_id', 'quotation_request_item_id', 'unit_price', 'lead_time_days'];

    protected $casts = ['supplier_quotation_id' => 'integer', 'quotation_request_item_id' => 'integer', 'unit_price' => 'decimal:4', 'lead_time_days' => 'integer'];

    /**
     * @return BelongsTo<QuotationRequestItem, $this>
     */
    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(QuotationRequestItem::class, 'quotation_request_item_id');
    }
}
