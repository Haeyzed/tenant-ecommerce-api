<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A downloadable file of a digital product (spec §28.4). Stored on the
 * private disk; delivered only through download grants and temporary
 * signed URLs.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $download_limit
 * @property int|null $expires_after_days
 * @property-read Product $product
 */
class DigitalProductFile extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'download_limit', 'expires_after_days'];

    protected $casts = ['download_limit' => 'integer', 'expires_after_days' => 'integer'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile()->useDisk('local');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
