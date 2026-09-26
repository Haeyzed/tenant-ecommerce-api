<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 * @property bool $is_active
 */
class CmsBlogCategory extends CmsModel
{
    protected $table = 'cms_blog_categories';

    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];
}
