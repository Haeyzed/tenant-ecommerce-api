<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An appointment (spec §66.1, §66.2). pending_payment and confirmed hold
 * their slot.
 *
 * @property int $id
 * @property int $product_id
 * @property int $booking_staff_id
 * @property int|null $customer_id
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property Carbon $start_datetime
 * @property Carbon $end_datetime
 * @property string $status
 * @property int|null $order_id
 * @property string|null $notes
 * @property-read Product $product
 * @property-read BookingStaff $staff
 * @property-read Customer|null $customer
 * @property-read Order|null $order
 */
class Booking extends Model implements AuditableContract
{
    use Auditable;

    public const string PENDING_PAYMENT = 'pending_payment';

    public const string CONFIRMED = 'confirmed';

    public const string COMPLETED = 'completed';

    public const string CANCELLED = 'cancelled';

    public const string NO_SHOW = 'no_show';

    public const array STATUSES = [self::PENDING_PAYMENT, self::CONFIRMED, self::COMPLETED, self::CANCELLED, self::NO_SHOW];

    /** Statuses that hold their slot (A-57). */
    public const array HOLDING = [self::PENDING_PAYMENT, self::CONFIRMED];

    protected $connection = 'tenant';

    protected $fillable = [];

    /** @var list<string> guest contact details stay out of the audit trail */
    protected array $auditExclude = ['guest_email', 'guest_phone'];

    protected $casts = [
        'product_id' => 'integer',
        'booking_staff_id' => 'integer',
        'customer_id' => 'integer',
        'order_id' => 'integer',
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<BookingStaff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(BookingStaff::class, 'booking_staff_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}
