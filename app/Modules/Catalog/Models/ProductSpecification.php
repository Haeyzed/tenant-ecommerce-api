<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A descriptive, non-purchasable fact about a product (spec §29.3).
 *
 * @property int $id
 * @property int $product_id
 * @property string|null $spec_group
 * @property string $spec_key
 * @property string $spec_value
 * @property int $sort_order
 */
class ProductSpecification extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'spec_group', 'spec_key', 'spec_value', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];
}
