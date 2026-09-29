<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Shared\Media\MediaDisks;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A file on an employee's record (spec §58.2), on the private disk.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $document_type_id
 * @property Carbon|null $expiry_date
 * @property string|null $notes
 * @property Carbon $created_at
 * @property-read HrEmployee $employee
 * @property-read HrDocumentType $documentType
 */
class HrEmployeeDocument extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $connection = 'tenant';

    protected $table = 'hr_employee_documents';

    protected $fillable = [];

    protected $casts = [
        'employee_id' => 'integer',
        'document_type_id' => 'integer',
        'expiry_date' => 'date',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->useDisk(MediaDisks::PRIVATE)->singleFile();
    }

    /**
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id')->withTrashed();
    }

    /**
     * @return BelongsTo<HrDocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(HrDocumentType::class, 'document_type_id');
    }
}
