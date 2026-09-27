<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One record going through a workflow (spec §60.2, §60.3).
 *
 * @property int $id
 * @property int $approval_workflow_id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property int|null $requested_by_user_id
 * @property int $current_step_order
 * @property string $status pending | approved | rejected | cancelled
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property-read ApprovalWorkflow $workflow
 * @property-read Model|null $approvable
 * @property-read User|null $requester
 * @property-read Collection<int, ApprovalAction> $actions
 */
class ApprovalRequest extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'approval_workflow_id' => 'integer',
        'approvable_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'current_step_order' => 'integer',
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id')->withTrashed();
    }

    /**
     * @return HasMany<ApprovalAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('id');
    }
}
