<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A page built from an optional body and ordered sections (spec §24.2).
 * System pages (seeded, with a system_key) cannot be deleted.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $system_key
 * @property bool $is_homepage
 * @property string|null $body
 * @property string $status
 * @property Carbon|null $published_at
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $canonical_url
 * @property string $robots
 * @property Carbon $updated_at
 */
class CmsPage extends CmsModel implements AuditableContract, HasMedia
{
    use Auditable;
    use InteractsWithMedia;

    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    public const array ROBOTS = ['index_follow', 'noindex_follow', 'noindex_nofollow'];

    protected $table = 'cms_pages';

    protected $fillable = ['title', 'slug', 'body', 'meta_title', 'meta_description', 'canonical_url', 'robots'];

    protected $casts = [
        'is_homepage' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * @return HasMany<CmsPageSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(CmsPageSection::class, 'cms_page_id')->orderBy('sort_order')->orderBy('id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('og_image')->singleFile()->useDisk('public');
        $this->addMediaCollection('section_media')->useDisk('public');
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }
}
