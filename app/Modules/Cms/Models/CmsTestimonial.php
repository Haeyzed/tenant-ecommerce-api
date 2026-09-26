<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Curated by hand; never pulled from product reviews (spec §24.4).
 *
 * @property int $id
 * @property string $customer_name
 * @property string|null $customer_title
 * @property string $quote
 * @property int|null $rating
 * @property bool $is_featured
 * @property int $sort_order
 * @property bool $is_active
 */
class CmsTestimonial extends CmsModel implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'cms_testimonials';

    protected $fillable = ['customer_name', 'customer_title', 'quote', 'rating', 'is_featured', 'sort_order', 'is_active'];

    protected $casts = ['rating' => 'integer', 'is_featured' => 'boolean', 'sort_order' => 'integer', 'is_active' => 'boolean'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->singleFile()->useDisk('public');
    }
}
