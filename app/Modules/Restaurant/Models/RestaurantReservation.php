<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Models;

use App\Modules\Customers\Models\Customer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $restaurant_table_id
 * @property int|null $customer_id
 * @property string $customer_name
 * @property string|null $customer_phone
 * @property int $party_size
 * @property Carbon $reservation_time
 * @property string $status
 * @property int|null $order_id
 * @property string|null $notes
 * @property-read RestaurantTable $table
 * @property-read Customer|null $customer
 */
class RestaurantReservation extends Model
{
    public const string CONFIRMED = 'confirmed';

    public const string SEATED = 'seated';

    public const string COMPLETED = 'completed';

    public const string CANCELLED = 'cancelled';

    public const string NO_SHOW = 'no_show';

    public const array STATUSES = [self::CONFIRMED, self::SEATED, self::COMPLETED, self::CANCELLED, self::NO_SHOW];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'restaurant_table_id' => 'integer',
        'customer_id' => 'integer',
        'party_size' => 'integer',
        'reservation_time' => 'datetime',
        'order_id' => 'integer',
    ];

    /**
     * @return BelongsTo<RestaurantTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
