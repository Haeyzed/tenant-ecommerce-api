<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * An image banner or an announcement (spec §24.5). Live when active and
 * within its optional start and end.
 *
 * @property int $id
 * @property string $kind image | announcement
 * @property string $title
 * @property string|null $body
 * @property string|null $link_url
 * @property string|null $link_label
 * @property string|null $position
 * @property string|null $background_color
 * @property string|null $text_color
 * @property bool $is_dismissible
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property int $sort_order
 */
class CmsBanner extends CmsModel implements HasMedia
{
    use InteractsWithMedia;

    public const string IMAGE = 'image';

    public const string ANNOUNCEMENT = 'announcement';

    protected $table = 'cms_banners';

    protected $fillable = [
        'kind', 'title', 'body', 'link_url', 'link_label', 'position', 'background_color', 'text_color', 'is_dismissible',
        'starts_at', 'ends_at', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_dismissible' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()->useDisk('public');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(static fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(static fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
