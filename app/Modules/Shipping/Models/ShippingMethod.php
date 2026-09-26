<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A courier or in-house delivery option of a zone, with a flat cost per
 * order in the base currency (spec §36.1, Assumption A-17). Soft-deleted,
 * because orders and shipments keep referencing it.
 *
 * @property int $id
 * @property int $shipping_zone_id
 * @property string $name
 * @property string $fulfillment_type courier | in_house
 * @property string|null $courier_provider
 * @property string $cost
 * @property int|null $estimated_days
 * @property bool $is_active
 * @property-read ShippingZone $zone
 */
class ShippingMethod extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    public const string COURIER = 'courier';

    public const string IN_HOUSE = 'in_house';

    protected $connection = 'tenant';

    protected $fillable = ['shipping_zone_id', 'name', 'fulfillment_type', 'courier_provider', 'cost', 'estimated_days', 'is_active'];

    protected $casts = [
        'shipping_zone_id' => 'integer',
        'cost' => 'decimal:4',
        'estimated_days' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }
}
