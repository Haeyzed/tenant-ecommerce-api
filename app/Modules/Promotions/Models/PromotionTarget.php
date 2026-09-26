<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An include or exclude row of a promotion (spec §37.3). target_id names a
 * row of the table named by target_type (a closed set, so no morph type).
 *
 * @property int $id
 * @property int $promotion_id
 * @property string $target_type
 * @property int $target_id
 * @property string $mode include | exclude
 */
class PromotionTarget extends Model
{
    /** Dimensions matched against basket lines. */
    public const array LINE_TYPES = ['product', 'product_variant', 'category', 'brand', 'seller', 'warehouse'];

    /** Dimensions matched against the buyer. */
    public const array BUYER_TYPES = ['customer', 'customer_group'];

    public const array TYPES = [...self::LINE_TYPES, ...self::BUYER_TYPES];

    /** @var array<string, string> the table of each target type */
    public const array TABLES = [
        'product' => 'products',
        'product_variant' => 'product_variants',
        'category' => 'categories',
        'brand' => 'brands',
        'seller' => 'sellers',
        'warehouse' => 'warehouses',
        'customer' => 'customers',
        'customer_group' => 'customer_groups',
    ];

    public $timestamps = false;

    protected $connection = 'tenant';

    protected $fillable = ['target_type', 'target_id', 'mode'];

    protected $casts = ['promotion_id' => 'integer', 'target_id' => 'integer'];
}
