<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's earning on one order line (sale) or its reversal (spec
 * §50.4), in the base currency. Reversals are negative and name their
 * cause (source); the unique key makes each cause idempotent.
 *
 * @property int $id
 * @property int $seller_id
 * @property int $order_id
 * @property int $order_item_id
 * @property string $entry_type
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $gross_amount
 * @property string $commission_rate_applied
 * @property string $commission_amount
 * @property string $net_payable
 * @property Carbon|null $available_at
 * @property int|null $seller_payout_id
 * @property Carbon $created_at
 * @property-read Order $order
 */
class SellerLedgerEntry extends Model
{
    public const string SALE = 'sale';

    public const string REVERSAL = 'reversal';

    public const UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'seller_id' => 'integer',
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'source_id' => 'integer',
        'gross_amount' => 'decimal:4',
        'commission_rate_applied' => 'decimal:4',
        'commission_amount' => 'decimal:4',
        'net_payable' => 'decimal:4',
        'available_at' => 'datetime',
        'seller_payout_id' => 'integer',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
