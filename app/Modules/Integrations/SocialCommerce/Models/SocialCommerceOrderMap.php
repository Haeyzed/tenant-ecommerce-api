<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $social_commerce_account_id
 * @property int $order_id
 * @property string $external_order_id
 * @property Carbon|null $last_synced_at
 */
class SocialCommerceOrderMap extends Model
{
    protected $table = 'social_commerce_order_map';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['social_commerce_account_id' => 'integer', 'order_id' => 'integer', 'last_synced_at' => 'datetime'];
}
