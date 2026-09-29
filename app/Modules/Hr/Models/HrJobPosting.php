<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * A job opening (spec §58.7): draft, then open on the public careers page,
 * then closed.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property int|null $department_id
 * @property string $description
 * @property string|null $requirements
 * @property string|null $location
 * @property string $employment_type
 * @property string $status
 * @property Carbon|null $application_deadline
 * @property Carbon|null $posted_at
 * @property Carbon|null $closed_at
 * @property-read HrDepartment|null $department
 */
class HrJobPosting extends Model
{
    use HasSlug;

    public const string DRAFT = 'draft';

    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    public const array STATUSES = [self::DRAFT, self::OPEN, self::CLOSED];

    protected $connection = 'tenant';

    protected $table = 'hr_job_postings';

    protected $fillable = [];

    protected $casts = [
        'department_id' => 'integer',
        'application_deadline' => 'date',
        'posted_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('title')->saveSlugsTo('slug')->preventOverwrite()->doNotGenerateSlugsOnUpdate();
    }

    /**
     * Open, and the deadline (if any) is not past.
     */
    public function acceptsApplications(): bool
    {
        return $this->status === self::OPEN && ($this->application_deadline === null || ! $this->application_deadline->isBefore(today()));
    }

    /**
     * @return BelongsTo<HrDepartment, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    /**
     * @return HasMany<HrJobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(HrJobApplication::class, 'job_posting_id');
    }
}
