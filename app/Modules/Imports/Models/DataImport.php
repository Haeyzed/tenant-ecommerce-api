<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use App\Modules\Users\Models\User;
use App\Shared\Media\MediaDisks;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A spreadsheet import (D-135): queued → processing → completed |
 * completed_with_errors | failed. last_row is the last row fully applied
 * (the heading is row 1).
 *
 * @property int $id
 * @property string $import_type
 * @property string $mode create | update | upsert
 * @property string $status
 * @property string $original_filename
 * @property int $requested_by_id
 * @property int $last_row
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $failed_count
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property-read User $requestedBy
 */
class DataImport extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const string QUEUED = 'queued';

    public const string PROCESSING = 'processing';

    public const string COMPLETED = 'completed';

    public const string COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const string FAILED = 'failed';

    public const array STATUSES = [self::QUEUED, self::PROCESSING, self::COMPLETED, self::COMPLETED_WITH_ERRORS, self::FAILED];

    public const array MODES = ['create', 'update', 'upsert'];

    /** Days an uploaded file is kept; the import row and its errors stay. */
    public const int FILE_RETENTION_DAYS = 30;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'requested_by_id' => 'integer',
        'last_row' => 'integer',
        'processed_rows' => 'integer',
        'created_count' => 'integer',
        'updated_count' => 'integer',
        'failed_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        // Uploaded spreadsheets are untrusted and private (§75 rule 10).
        $this->addMediaCollection('file')->useDisk(MediaDisks::PRIVATE)->singleFile();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * @return HasMany<DataImportError, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(DataImportError::class)->orderBy('row_number')->orderBy('id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::COMPLETED_WITH_ERRORS, self::FAILED], true);
    }
}
