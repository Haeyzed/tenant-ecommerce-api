<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

/**
 * One ordered section of a page; settings validated against its type's
 * schema in config/cms.php (spec §24.2).
 *
 * @property int $id
 * @property int $cms_page_id
 * @property string $section_type
 * @property array<string, mixed> $settings
 * @property bool $is_active
 * @property int $sort_order
 */
class CmsPageSection extends CmsModel
{
    protected $table = 'cms_page_sections';

    protected $fillable = ['cms_page_id', 'section_type', 'settings', 'is_active', 'sort_order'];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
