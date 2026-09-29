<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Models;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dining area (spec §65.1), at the location (warehouse) whose stock and
 * POS registers it uses.
 *
 * @property int $id
 * @property string $name
 * @property int $warehouse_id
 * @property int $sort_order
 * @property-read Warehouse $warehouse
 */
class RestaurantFloor extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['warehouse_id' => 'integer', 'sort_order' => 'integer'];

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<RestaurantTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class)->orderBy('name');
    }
}
