<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An answer by staff ("user") or a marketplace seller ("seller").
 *
 * @property int $id
 * @property int $product_question_id
 * @property string $answered_by_type user | seller
 * @property int $answered_by_id
 * @property string $answer
 * @property bool $is_approved
 * @property-read ProductQuestion $question
 */
class ProductAnswer extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_question_id', 'answered_by_type', 'answered_by_id', 'answer', 'is_approved'];

    protected $casts = ['product_question_id' => 'integer', 'answered_by_id' => 'integer', 'is_approved' => 'boolean'];

    /**
     * @return BelongsTo<ProductQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class, 'product_question_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function answeredBy(): MorphTo
    {
        return $this->morphTo('answered_by');
    }
}
