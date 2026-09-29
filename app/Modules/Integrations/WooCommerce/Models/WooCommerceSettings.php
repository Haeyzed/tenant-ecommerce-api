<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * The WooCommerce connection (spec §68.1), a singleton row. The consumer
 * key and secret are encrypted and never serialised (§5.6).
 *
 * @property int $id
 * @property string|null $store_url
 * @property string|null $consumer_key
 * @property string|null $consumer_secret
 * @property bool $is_active
 * @property bool $sync_products
 * @property bool $sync_categories
 * @property bool $sync_orders
 * @property bool $sync_tax_rates
 * @property string $order_sync_direction import | export
 * @property int|null $import_warehouse_id
 * @property int $sync_interval_minutes
 * @property string|null $sync_chain_id
 * @property Carbon|null $activated_at
 * @property Carbon|null $last_synced_at
 */
class WooCommerceSettings extends Model
{
    public const array DIRECTIONS = ['import', 'export'];

    protected $table = 'woocommerce_settings';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $hidden = ['consumer_key', 'consumer_secret'];

    protected $casts = [
        'consumer_key' => 'encrypted',
        'consumer_secret' => 'encrypted',
        'is_active' => 'boolean',
        'sync_products' => 'boolean',
        'sync_categories' => 'boolean',
        'sync_orders' => 'boolean',
        'sync_tax_rates' => 'boolean',
        'import_warehouse_id' => 'integer',
        'sync_interval_minutes' => 'integer',
        'activated_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function hasCredentials(): bool
    {
        return $this->store_url !== null && $this->consumer_key !== null && $this->consumer_secret !== null;
    }
}
