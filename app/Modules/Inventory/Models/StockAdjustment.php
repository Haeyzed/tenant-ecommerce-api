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
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A batch correction of one warehouse's stock (spec §33.2). Immutable once
 * submitted.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property string $status
 * @property string|null $notes
 * @property int|null $attachment_media_id
 * @property int $created_by
 * @property Carbon|null $submitted_at
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, StockAdjustmentItem> $items
 */
class StockAdjustment extends Model implements AuditableContract, HasMedia
{
    use Auditable;
    use InteractsWithMedia;

    public const string DRAFT = 'draft';

    public const string SUBMITTED = 'submitted';

    protected $connection = 'tenant';

    protected $fillable = ['notes'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'attachment_media_id' => 'integer',
        'created_by' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachment')->singleFile()->useDisk('local');
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
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * @return HasMany<StockAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('id');
    }
}
