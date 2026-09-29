<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Better terms for a set of sellers (spec §50.1): members without their
 * own rate use the group's default_commission_rate.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $default_commission_rate
 */
class SellerGroup extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'description', 'default_commission_rate'];

    protected $casts = ['default_commission_rate' => 'decimal:4'];

    /**
     * @return HasMany<Seller, $this>
     */
    public function sellers(): HasMany
    {
        return $this->hasMany(Seller::class);
    }
}
