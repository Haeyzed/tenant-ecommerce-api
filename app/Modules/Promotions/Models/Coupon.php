<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A code that unlocks a coupon-triggered promotion (spec §37.4). Stored
 * trimmed and upper-cased.
 *
 * @property int $id
 * @property int $promotion_id
 * @property string $code
 * @property int|null $usage_limit
 * @property int $times_redeemed
 * @property int|null $assigned_customer_id
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property-read Promotion $promotion
 */
class Coupon extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['code', 'usage_limit', 'assigned_customer_id', 'expires_at', 'is_active'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['times_redeemed'];

    protected $casts = [
        'promotion_id' => 'integer',
        'usage_limit' => 'integer',
        'times_redeemed' => 'integer',
        'assigned_customer_id' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function assignedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'assigned_customer_id')->withTrashed();
    }
}
