<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A project (spec §63.1): a category, an optional customer, assigned
 * staff users, and tasks.
 *
 * @property int $id
 * @property string $title
 * @property int $project_category_id
 * @property int|null $customer_id
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string $priority
 * @property string $status
 * @property bool $notify_assigned_employees_whatsapp
 * @property bool $notify_customer_whatsapp
 * @property string|null $description
 * @property int $created_by_user_id
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property-read ProjectCategory $category
 * @property-read Customer|null $customer
 * @property-read User $creator
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, ProjectTask> $tasks
 */
class Project extends Model implements AuditableContract
{
    use Auditable;

    public const array PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public const string COMPLETED = 'completed';

    public const array STATUSES = ['not_started', 'in_progress', 'on_hold', 'completed', 'cancelled'];

    /** Statuses that no longer count as work in progress. */
    public const array CLOSED = ['completed', 'cancelled'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'project_category_id' => 'integer',
        'customer_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'notify_assigned_employees_whatsapp' => 'boolean',
        'notify_customer_whatsapp' => 'boolean',
        'created_by_user_id' => 'integer',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ProjectCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'project_category_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user');
    }

    /**
     * @return HasMany<ProjectTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }
}
