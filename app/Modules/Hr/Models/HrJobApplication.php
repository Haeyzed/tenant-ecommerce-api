<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $job_posting_id
 * @property int $candidate_id
 * @property string|null $cover_letter
 * @property string $status
 * @property Carbon|null $status_changed_at
 * @property string|null $notes internal; never on public routes
 * @property Carbon $applied_at
 * @property int|null $converted_employee_id
 * @property-read HrJobPosting $posting
 * @property-read HrCandidate $candidate
 */
class HrJobApplication extends Model
{
    public const string APPLIED = 'applied';

    public const string HIRED = 'hired';

    public const array STATUSES = ['applied', 'shortlisted', 'interviewing', 'offered', 'hired', 'rejected'];

    protected $connection = 'tenant';

    protected $table = 'hr_job_applications';

    protected $fillable = [];

    protected $casts = [
        'job_posting_id' => 'integer',
        'candidate_id' => 'integer',
        'status_changed_at' => 'datetime',
        'applied_at' => 'datetime',
        'converted_employee_id' => 'integer',
    ];

    /**
     * @return BelongsTo<HrJobPosting, $this>
     */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(HrJobPosting::class, 'job_posting_id');
    }

    /**
     * @return BelongsTo<HrCandidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }
}
