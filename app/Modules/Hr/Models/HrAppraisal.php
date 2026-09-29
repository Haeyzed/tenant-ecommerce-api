<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $appraisal_template_id
 * @property int $reviewer_user_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status
 * @property string|null $overall_score
 * @property string|null $overall_comments
 * @property Carbon|null $submitted_at
 * @property Carbon|null $acknowledged_at
 * @property-read HrEmployee $employee
 * @property-read HrAppraisalTemplate $template
 * @property-read User $reviewer
 * @property-read Collection<int, HrAppraisalScore> $scores
 */
class HrAppraisal extends Model
{
    public const string DRAFT = 'draft';

    public const string SUBMITTED = 'submitted';

    public const string ACKNOWLEDGED = 'acknowledged';

    protected $connection = 'tenant';

    protected $table = 'hr_appraisals';

    protected $fillable = [];

    protected $casts = [
        'employee_id' => 'integer',
        'appraisal_template_id' => 'integer',
        'reviewer_user_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'overall_score' => 'decimal:2',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id')->withTrashed();
    }

    /**
     * @return BelongsTo<HrAppraisalTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(HrAppraisalTemplate::class, 'appraisal_template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /**
     * @return HasMany<HrAppraisalScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(HrAppraisalScore::class, 'appraisal_id');
    }
}
