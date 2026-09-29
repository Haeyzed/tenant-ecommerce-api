<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Shared\Media\MediaDisks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A file attached to a support message (spec §59.1), on the private disk.
 *
 * @property int $id
 * @property int $support_message_id
 * @property-read SupportMessage $message
 */
class SupportMessageAttachment extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $connection = 'tenant';

    protected $fillable = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachment')->useDisk(MediaDisks::PRIVATE)->singleFile();
    }

    /**
     * @return BelongsTo<SupportMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }
}
