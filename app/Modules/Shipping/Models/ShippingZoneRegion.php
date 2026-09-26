<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A country, or one state of it, in a shipping zone (spec §36.1).
 *
 * @property int $id
 * @property int $shipping_zone_id
 * @property int $country_id
 * @property int|null $state_id
 */
class ShippingZoneRegion extends Model
{
    public $timestamps = false;

    protected $connection = 'tenant';

    protected $fillable = ['country_id', 'state_id'];

    protected $casts = [
        'shipping_zone_id' => 'integer',
        'country_id' => 'integer',
        'state_id' => 'integer',
    ];
}
