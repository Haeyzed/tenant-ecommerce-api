<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $appraisal_id
 * @property int $appraisal_template_criterion_id
 * @property string $score
 * @property string|null $comments
 * @property-read HrAppraisalTemplateCriterion $criterion
 */
class HrAppraisalScore extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_appraisal_scores';

    protected $fillable = [];

    protected $casts = [
        'appraisal_id' => 'integer',
        'appraisal_template_criterion_id' => 'integer',
        'score' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<HrAppraisalTemplateCriterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(HrAppraisalTemplateCriterion::class, 'appraisal_template_criterion_id');
    }
}
