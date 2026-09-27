<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One step of a workflow (spec §60.2): any_one approver completes it, or
 * all approver rows must be satisfied (A-52).
 *
 * @property int $id
 * @property int $approval_workflow_id
 * @property int $step_order
 * @property string $name
 * @property string $approval_mode any_one | all
 * @property-read Collection<int, ApprovalStepApprover> $approvers
 * @property-read ApprovalWorkflow $workflow
 */
class ApprovalStep extends Model
{
    public const string ANY_ONE = 'any_one';

    public const string ALL = 'all';

    protected $connection = 'tenant';

    protected $fillable = ['name', 'approval_mode'];

    protected $casts = ['approval_workflow_id' => 'integer', 'step_order' => 'integer'];

    /**
     * @return HasMany<ApprovalStepApprover, $this>
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalStepApprover::class);
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }
}
