<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task with one assignee (spec §63.1).
 *
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string|null $estimated_hours
 * @property int|null $assigned_user_id
 * @property string $status
 * @property bool $send_whatsapp_notification
 * @property string|null $description
 * @property Carbon|null $completed_at
 * @property-read Project $project
 * @property-read User|null $assignee
 */
class ProjectTask extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'project_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'estimated_hours' => 'decimal:2',
        'assigned_user_id' => 'integer',
        'send_whatsapp_notification' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function isOverdue(): bool
    {
        return $this->end_date !== null && $this->end_date->isBefore(today()) && ! in_array($this->status, Project::CLOSED, true);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
