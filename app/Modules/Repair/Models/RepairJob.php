<?php

declare(strict_types=1);

namespace App\Modules\Repair\Models;

use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A customer's item in for repair (spec §67.1, §67.2).
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property int|null $booking_id
 * @property string $item_description
 * @property int $warehouse_id
 * @property string $status
 * @property string|null $diagnosis_notes
 * @property string|null $estimated_cost
 * @property Carbon|null $customer_approved_at
 * @property int|null $order_id
 * @property int|null $created_by_user_id
 * @property Carbon $received_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $picked_up_at
 * @property-read Customer|null $customer
 * @property-read Warehouse $warehouse
 * @property-read Order|null $order
 * @property-read Booking|null $booking
 * @property-read Collection<int, RepairJobPart> $parts
 * @property-read Collection<int, RepairJobLabor> $labor
 */
class RepairJob extends Model implements AuditableContract
{
    use Auditable;

    public const string RECEIVED = 'received';

    public const string DIAGNOSING = 'diagnosing';

    public const string AWAITING_APPROVAL = 'awaiting_approval';

    public const string IN_REPAIR = 'in_repair';

    public const string COMPLETED = 'completed';

    public const string PICKED_UP = 'picked_up';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::RECEIVED, self::DIAGNOSING, self::AWAITING_APPROVAL, self::IN_REPAIR, self::COMPLETED, self::PICKED_UP, self::CANCELLED];

    protected $connection = 'tenant';

    protected $fillable = [];

    /** @var list<string> */
    protected array $auditExclude = ['customer_phone'];

    protected $casts = [
        'customer_id' => 'integer',
        'booking_id' => 'integer',
        'warehouse_id' => 'integer',
        'estimated_cost' => 'decimal:4',
        'customer_approved_at' => 'datetime',
        'order_id' => 'integer',
        'created_by_user_id' => 'integer',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
        'picked_up_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return HasMany<RepairJobPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(RepairJobPart::class)->orderBy('id');
    }

    /**
     * @return HasMany<RepairJobLabor, $this>
     */
    public function labor(): HasMany
    {
        return $this->hasMany(RepairJobLabor::class)->orderBy('id');
    }
}
