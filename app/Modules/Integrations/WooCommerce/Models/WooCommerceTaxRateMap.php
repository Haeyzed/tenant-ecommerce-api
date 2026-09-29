<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tax_rate_id
 * @property int $woocommerce_tax_rate_id
 * @property Carbon|null $last_synced_at
 */
class WooCommerceTaxRateMap extends Model
{
    protected $table = 'woocommerce_tax_rate_map';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['tax_rate_id' => 'integer', 'woocommerce_tax_rate_id' => 'integer', 'last_synced_at' => 'datetime'];
}
