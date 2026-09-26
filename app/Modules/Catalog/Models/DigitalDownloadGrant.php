<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The right to download one file of a bought digital line (spec §28.4).
 *
 * @property int $id
 * @property int $order_item_id
 * @property int $digital_product_file_id
 * @property int|null $customer_id
 * @property int $download_count
 * @property int|null $download_limit
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property-read OrderItem $orderItem
 * @property-read DigitalProductFile $file
 */
class DigitalDownloadGrant extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'order_item_id' => 'integer',
        'digital_product_file_id' => 'integer',
        'customer_id' => 'integer',
        'download_count' => 'integer',
        'download_limit' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Why the grant cannot be used, or null when it can.
     */
    public function unusableReason(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'download_revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'download_expired',
            $this->download_limit !== null && $this->download_count >= $this->download_limit => 'download_limit_reached',
            default => null,
        };
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<DigitalProductFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(DigitalProductFile::class, 'digital_product_file_id');
    }
}
