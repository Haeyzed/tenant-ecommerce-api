<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A set of countries and states served by the same shipping methods (spec
 * §36.1).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property-read Collection<int, ShippingZoneRegion> $regions
 * @property-read Collection<int, ShippingMethod> $methods
 */
class ShippingZone extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * @return HasMany<ShippingZoneRegion, $this>
     */
    public function regions(): HasMany
    {
        return $this->hasMany(ShippingZoneRegion::class)->orderBy('country_id')->orderBy('state_key');
    }

    /**
     * @return HasMany<ShippingMethod, $this>
     */
    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class)->orderBy('cost')->orderBy('id');
    }
}
