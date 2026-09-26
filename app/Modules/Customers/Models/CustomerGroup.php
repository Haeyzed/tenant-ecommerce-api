<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A customer segment (spec §26.3). Exactly one group is the default;
 * customers with no group belong to it.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 */
class CustomerGroup extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'description'];

    protected $casts = ['is_default' => 'boolean'];

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
