<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $appraisal_template_id
 * @property string $label
 * @property string $weight
 * @property string $max_score
 * @property int $sort_order
 * @property-read HrAppraisalTemplate $template
 */
class HrAppraisalTemplateCriterion extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_appraisal_template_criteria';

    protected $fillable = [];

    protected $casts = [
        'appraisal_template_id' => 'integer',
        'weight' => 'decimal:4',
        'max_score' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo<HrAppraisalTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(HrAppraisalTemplate::class, 'appraisal_template_id');
    }
}
