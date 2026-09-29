<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Models;

use App\Modules\Catalog\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $social_commerce_account_id
 * @property int $product_id
 * @property string|null $external_product_id
 * @property string $sync_status synced | pending | failed | rejected
 * @property string|null $rejection_reason
 * @property Carbon|null $last_synced_at
 * @property-read Product $product
 */
class SocialCommerceProductMap extends Model
{
    public const array STATUSES = ['synced', 'pending', 'failed', 'rejected'];

    protected $table = 'social_commerce_product_map';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['social_commerce_account_id' => 'integer', 'product_id' => 'integer', 'last_synced_at' => 'datetime'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
