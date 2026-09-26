<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A unit products are sold in (spec §29.1). "Piece" is seeded and is the
 * default when a product has no unit.
 *
 * @property int $id
 * @property string $name
 * @property string $short_code
 * @property bool $allows_decimal
 */
class UnitOfMeasure extends Model
{
    public const string DEFAULT_CODE = 'pc';

    protected $connection = 'tenant';

    protected $table = 'units_of_measure';

    protected $fillable = ['name', 'short_code', 'allows_decimal'];

    protected $casts = ['allows_decimal' => 'boolean'];
}
