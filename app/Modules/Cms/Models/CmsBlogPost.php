<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int|null $cms_blog_category_id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body
 * @property int|null $author_id staff user or platform user, by scope; no foreign key
 * @property string $author_name
 * @property string $status
 * @property Carbon|null $published_at
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $canonical_url
 * @property Carbon $updated_at
 * @property-read CmsBlogCategory|null $category
 */
class CmsBlogPost extends CmsModel implements AuditableContract, HasMedia
{
    use Auditable;
    use InteractsWithMedia;

    protected $table = 'cms_blog_posts';

    protected $fillable = ['cms_blog_category_id', 'title', 'slug', 'excerpt', 'body', 'meta_title', 'meta_description', 'canonical_url'];

    protected $casts = ['published_at' => 'datetime'];

    /**
     * @return BelongsTo<CmsBlogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CmsBlogCategory::class, 'cms_blog_category_id');
    }

    /**
     * @return BelongsToMany<CmsTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CmsTag::class, 'cms_blog_post_tag', 'cms_blog_post_id', 'cms_tag_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover_image')->singleFile()->useDisk('public');
    }
}
