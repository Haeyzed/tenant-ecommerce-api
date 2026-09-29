<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A connected sales channel (spec §69.1). The access token is encrypted
 * and never serialised (§5.6).
 *
 * @property int $id
 * @property string $channel
 * @property string $access_token
 * @property string $account_reference the catalogue (Meta) or shop (TikTok) id
 * @property string|null $order_account_reference Facebook Shop: the commerce account id
 * @property bool $is_active
 * @property bool $sync_products
 * @property bool $sync_orders
 * @property int|null $fulfilment_warehouse_id
 * @property int $sync_interval_minutes
 * @property string|null $sync_chain_id
 * @property Carbon|null $last_synced_at
 */
class SocialCommerceAccount extends Model
{
    public const string INSTAGRAM = 'instagram';

    public const string FACEBOOK_SHOP = 'facebook_shop';

    public const string TIKTOK_SHOP = 'tiktok_shop';

    public const string WHATSAPP_CATALOG = 'whatsapp_catalog';

    public const array CHANNELS = [self::INSTAGRAM, self::FACEBOOK_SHOP, self::TIKTOK_SHOP, self::WHATSAPP_CATALOG];

    /** Channels whose orders can be pulled (§69.2; D-131 for WhatsApp). */
    public const array ORDER_CHANNELS = [self::FACEBOOK_SHOP, self::TIKTOK_SHOP];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'is_active' => 'boolean',
        'sync_products' => 'boolean',
        'sync_orders' => 'boolean',
        'fulfilment_warehouse_id' => 'integer',
        'sync_interval_minutes' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function takesOrders(): bool
    {
        return in_array($this->channel, self::ORDER_CHANNELS, true);
    }
}
