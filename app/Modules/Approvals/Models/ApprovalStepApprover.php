<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/**
 * An approver of a step: a named staff user or any holder of a role.
 *
 * @property int $id
 * @property int $approval_step_id
 * @property string $approver_type role | user
 * @property int|null $role_id
 * @property int|null $user_id
 * @property-read Role|null $role
 * @property-read User|null $user
 */
class ApprovalStepApprover extends Model
{
    public const string ROLE = 'role';

    public const string USER = 'user';

    protected $connection = 'tenant';

    protected $fillable = ['approver_type', 'role_id', 'user_id'];

    protected $casts = ['approval_step_id' => 'integer', 'role_id' => 'integer', 'user_id' => 'integer'];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the user satisfies this row now (§60.3 step 3).
     */
    public function matches(User $user): bool
    {
        return $this->approver_type === self::USER
            ? $this->user_id === $user->id
            : $this->role !== null && $user->hasRole($this->role);
    }
}
