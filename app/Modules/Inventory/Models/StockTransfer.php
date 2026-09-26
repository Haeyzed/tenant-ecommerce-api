<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Stock moving between two warehouses (spec §33.1).
 *
 * @property int $id
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property string $status
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $received_at
 * @property-read Warehouse $fromWarehouse
 * @property-read Warehouse $toWarehouse
 * @property-read Collection<int, StockTransferItem> $items
 */
class StockTransfer extends Model implements AuditableContract
{
    use Auditable;

    public const string DRAFT = 'draft';

    public const string IN_TRANSIT = 'in_transit';

    public const string RECEIVED = 'received';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::DRAFT, self::IN_TRANSIT, self::RECEIVED, self::CANCELLED];

    protected $connection = 'tenant';

    protected $fillable = ['notes'];

    protected $casts = [
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'created_by' => 'integer',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('id');
    }
}
