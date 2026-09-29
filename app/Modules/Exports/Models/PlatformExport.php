<?php

declare(strict_types=1);

namespace App\Modules\Exports\Models;

use App\Modules\Access\Models\PlatformUser;
use App\Shared\Media\MediaDisks;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A platform (landlord) export (D-134): queued → processing → completed |
 * failed → expired, like a tenant DataExport.
 *
 * @property int $id
 * @property string $export_type
 * @property array<string, mixed> $parameters
 * @property string $format
 * @property string $status
 * @property int $requested_by_id
 * @property int|null $row_count
 * @property string|null $error
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read PlatformUser $requestedBy
 */
class PlatformExport extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $connection = 'landlord';

    protected $fillable = [];

    protected $casts = [
        'parameters' => 'array',
        'requested_by_id' => 'integer',
        'row_count' => 'integer',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->useDisk(MediaDisks::PRIVATE)->singleFile();
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'requested_by_id');
    }

    public function isDownloadable(): bool
    {
        return $this->status === DataExport::COMPLETED && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
