<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A production run (spec §64.1, §64.2): planned → in_progress (components
 * reserved) → completed (components consumed, finished goods added), or
 * cancelled.
 *
 * @property int $id
 * @property string $work_order_number
 * @property int $bill_of_material_id
 * @property int $warehouse_id
 * @property string $quantity_to_produce
 * @property string $status
 * @property Carbon|null $scheduled_start_date
 * @property Carbon|null $scheduled_end_date
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int $created_by_user_id
 * @property string|null $notes
 * @property-read BillOfMaterial $bom
 * @property-read Warehouse $warehouse
 * @property-read User $creator
 * @property-read Collection<int, WorkOrderMaterial> $materials
 */
class WorkOrder extends Model implements AuditableContract
{
    use Auditable;

    public const string PLANNED = 'planned';

    public const string IN_PROGRESS = 'in_progress';

    public const string COMPLETED = 'completed';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::PLANNED, self::IN_PROGRESS, self::COMPLETED, self::CANCELLED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'bill_of_material_id' => 'integer',
        'warehouse_id' => 'integer',
        'quantity_to_produce' => 'decimal:3',
        'scheduled_start_date' => 'date',
        'scheduled_end_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_by_user_id' => 'integer',
    ];

    /**
     * @return BelongsTo<BillOfMaterial, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_material_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<WorkOrderMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class)->orderBy('id');
    }
}
