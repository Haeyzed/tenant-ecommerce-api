<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 */
class CmsMenu extends CmsModel
{
    protected $table = 'cms_menus';

    protected $fillable = ['key', 'name'];

    /**
     * @return HasMany<CmsMenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CmsMenuItem::class, 'cms_menu_id')->orderBy('sort_order')->orderBy('id');
    }
}
