<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $category_id
 * @property int $woocommerce_category_id
 * @property Carbon|null $last_synced_at
 */
class WooCommerceCategoryMap extends Model
{
    protected $table = 'woocommerce_category_map';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['category_id' => 'integer', 'woocommerce_category_id' => 'integer', 'last_synced_at' => 'datetime'];
}
