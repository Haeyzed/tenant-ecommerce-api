<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int $woocommerce_order_id
 * @property string $direction imported | exported
 * @property Carbon|null $last_synced_at
 */
class WooCommerceOrderMap extends Model
{
    protected $table = 'woocommerce_order_map';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['order_id' => 'integer', 'woocommerce_order_id' => 'integer', 'last_synced_at' => 'datetime'];
}
