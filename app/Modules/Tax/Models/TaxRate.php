<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A tax rate for a country, or one of its states, and a tax class (spec
 * §35.1). A null state is the country-level rate.
 *
 * @property int $id
 * @property string $name
 * @property int $country_id
 * @property int|null $state_id
 * @property string $tax_class standard | reduced
 * @property string $rate_percentage
 * @property bool $is_active
 */
class TaxRate extends Model implements AuditableContract
{
    use Auditable;

    public const array CLASSES = ['standard', 'reduced'];

    protected $connection = 'tenant';

    protected $fillable = ['name', 'country_id', 'state_id', 'tax_class', 'rate_percentage', 'is_active'];

    protected $casts = [
        'country_id' => 'integer',
        'state_id' => 'integer',
        'rate_percentage' => 'decimal:4',
        'is_active' => 'boolean',
    ];
}
