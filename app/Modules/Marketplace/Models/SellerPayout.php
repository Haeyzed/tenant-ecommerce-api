<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A batch of a seller's available ledger entries for a period (spec
 * §50.5), paid outside the platform and then marked paid.
 *
 * @property int $id
 * @property int $seller_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $gross_amount
 * @property string $commission_amount
 * @property string $net_payable
 * @property string $status
 * @property Carbon|null $paid_at
 * @property string|null $reference
 * @property string|null $notes
 * @property int $created_by
 * @property-read Seller $seller
 */
class SellerPayout extends Model implements AuditableContract
{
    use Auditable;

    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'seller_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'gross_amount' => 'decimal:4',
        'commission_amount' => 'decimal:4',
        'net_payable' => 'decimal:4',
        'paid_at' => 'datetime',
        'created_by' => 'integer',
    ];

    /**
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class)->withTrashed();
    }
}
