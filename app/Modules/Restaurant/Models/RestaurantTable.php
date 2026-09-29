<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $restaurant_floor_id
 * @property string $name
 * @property int $seats
 * @property string $status
 * @property-read RestaurantFloor $floor
 */
class RestaurantTable extends Model
{
    public const string AVAILABLE = 'available';

    public const string OCCUPIED = 'occupied';

    public const string RESERVED = 'reserved';

    public const string CLEANING = 'cleaning';

    public const array STATUSES = [self::AVAILABLE, self::OCCUPIED, self::RESERVED, self::CLEANING];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['restaurant_floor_id' => 'integer', 'seats' => 'integer'];

    /**
     * @return BelongsTo<RestaurantFloor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(RestaurantFloor::class, 'restaurant_floor_id');
    }
}
