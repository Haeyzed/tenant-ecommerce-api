<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One approver's decision at one step (spec §60.2). Immutable: the history
 * of a request.
 *
 * @property int $id
 * @property int $approval_request_id
 * @property int $approval_step_id
 * @property string $step_name
 * @property int $step_order
 * @property int $approved_by_user_id
 * @property string $decision approved | rejected
 * @property string|null $note
 * @property Carbon $decided_at
 * @property-read User $approver
 */
class ApprovalAction extends Model
{
    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'approval_request_id' => 'integer',
        'approval_step_id' => 'integer',
        'step_order' => 'integer',
        'approved_by_user_id' => 'integer',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Approval actions are immutable.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id')->withTrashed();
    }
}
