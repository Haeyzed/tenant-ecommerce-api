<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class CmsTag extends CmsModel
{
    protected $table = 'cms_tags';

    protected $fillable = ['name', 'slug'];
}
