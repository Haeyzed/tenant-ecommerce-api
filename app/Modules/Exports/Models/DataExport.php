<?php

declare(strict_types=1);

namespace App\Modules\Exports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One asynchronous export (spec §19.4). The file lives in the private
 * "file" collection and is deleted after expires_at.
 *
 * @property int $id
 * @property string $export_type
 * @property array<string, mixed> $parameters
 * @property string $format csv | json | pdf
 * @property string $status queued | processing | completed | failed | expired
 * @property string $requested_by_type
 * @property int $requested_by_id
 * @property int|null $row_count
 * @property string|null $error
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 */
class DataExport extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const string QUEUED = 'queued';

    public const string PROCESSING = 'processing';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    public const string EXPIRED = 'expired';

    public const int RETENTION_DAYS = 7;

    protected $connection = 'tenant';

    protected $fillable = ['export_type', 'parameters', 'format', 'status', 'requested_by_type', 'requested_by_id', 'row_count', 'error', 'completed_at', 'expires_at'];

    protected $casts = [
        'parameters' => 'array',
        'row_count' => 'integer',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        // Private disk: never served from a public URL (§75 rule 10).
        $this->addMediaCollection('file')->useDisk('local')->singleFile();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::COMPLETED && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
