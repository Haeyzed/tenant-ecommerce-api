<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer-selectable dimension, e.g. Size (spec §28.2).
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order
 */
class ProductOption extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['name', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    /**
     * @return HasMany<ProductOptionValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('sort_order')->orderBy('id');
    }
}
