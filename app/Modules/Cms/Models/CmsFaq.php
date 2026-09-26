<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

/**
 * @property int $id
 * @property int|null $cms_faq_category_id
 * @property string $question
 * @property string $answer
 * @property int $sort_order
 * @property bool $is_active
 */
class CmsFaq extends CmsModel
{
    protected $table = 'cms_faqs';

    protected $fillable = ['cms_faq_category_id', 'question', 'answer', 'sort_order', 'is_active'];

    protected $casts = ['cms_faq_category_id' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
}
