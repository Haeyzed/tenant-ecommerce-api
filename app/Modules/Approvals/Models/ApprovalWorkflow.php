<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A configured approval workflow for one module key (spec §60.2). The first
 * active workflow (by sort_order) whose trigger conditions match a record
 * wins.
 *
 * @property int $id
 * @property string $name
 * @property string $module_key
 * @property array<string, mixed>|null $trigger_conditions
 * @property bool $is_active
 * @property int $sort_order
 * @property-read Collection<int, ApprovalStep> $steps
 */
class ApprovalWorkflow extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'module_key', 'trigger_conditions', 'sort_order'];

    protected $casts = ['trigger_conditions' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    /**
     * @return HasMany<ApprovalStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('step_order');
    }

    /**
     * @return HasMany<ApprovalRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }
}
