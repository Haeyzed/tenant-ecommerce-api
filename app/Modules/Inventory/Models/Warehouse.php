<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An internal stock location (spec §32.2). Customers never see it.
 *
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property string|null $address_line
 * @property int|null $city_id
 * @property int|null $state_id
 * @property int|null $country_id
 * @property string|null $phone
 * @property bool $is_active
 */
class Warehouse extends Model implements AuditableContract
{
    use Auditable;

    public const string DEFAULT_CODE = 'MAIN';

    protected $connection = 'tenant';

    protected $fillable = ['name', 'code', 'address_line', 'city_id', 'state_id', 'country_id', 'phone'];

    protected $casts = [
        'city_id' => 'integer',
        'state_id' => 'integer',
        'country_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'warehouse_user');
    }
}
