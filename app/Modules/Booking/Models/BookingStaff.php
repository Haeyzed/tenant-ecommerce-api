<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff user who can be booked (spec §66.1).
 *
 * @property int $id
 * @property int $user_id
 * @property bool $is_active
 * @property-read User $user
 * @property-read Collection<int, BookingAvailability> $availability
 * @property-read Collection<int, Product> $products
 */
class BookingStaff extends Model
{
    protected $table = 'booking_staff';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['user_id' => 'integer', 'is_active' => 'boolean'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BookingAvailability, $this>
     */
    public function availability(): HasMany
    {
        return $this->hasMany(BookingAvailability::class)->orderBy('day_of_week')->orderBy('start_time');
    }

    /**
     * @return HasMany<BookingTimeOff, $this>
     */
    public function timeOff(): HasMany
    {
        return $this->hasMany(BookingTimeOff::class)->orderBy('start_datetime');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_booking_staff');
    }
}
