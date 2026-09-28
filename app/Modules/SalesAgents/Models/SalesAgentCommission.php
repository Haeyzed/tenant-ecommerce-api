<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents\Models;

use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order's commission (spec §52.1), in the base currency.
 *
 * @property int $id
 * @property int $sales_agent_id
 * @property int $order_id
 * @property string $gross_amount
 * @property string $commission_rate_applied
 * @property string $commission_amount
 * @property string $status
 * @property Carbon $earned_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property-read SalesAgent $agent
 * @property-read Order $order
 */
class SalesAgentCommission extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string PAID = 'paid';

    public const array STATUSES = [self::PENDING, self::APPROVED, self::PAID];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'sales_agent_id' => 'integer',
        'order_id' => 'integer',
        'gross_amount' => 'decimal:4',
        'commission_rate_applied' => 'decimal:4',
        'commission_amount' => 'decimal:4',
        'earned_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<SalesAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(SalesAgent::class, 'sales_agent_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
