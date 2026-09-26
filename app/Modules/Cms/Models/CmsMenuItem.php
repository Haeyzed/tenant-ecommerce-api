<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

/**
 * @property int $id
 * @property int $cms_menu_id
 * @property int|null $parent_id
 * @property string $label
 * @property string $link_type page | url | blog | category | brand | product
 * @property int|null $linkable_id
 * @property string|null $url
 * @property bool $open_in_new_tab
 * @property bool $is_active
 * @property int $sort_order
 */
class CmsMenuItem extends CmsModel
{
    protected $table = 'cms_menu_items';

    protected $fillable = ['cms_menu_id', 'parent_id', 'label', 'link_type', 'linkable_id', 'url', 'open_in_new_tab', 'is_active', 'sort_order'];

    protected $casts = [
        'linkable_id' => 'integer',
        'open_in_new_tab' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
