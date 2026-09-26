<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A file attached to a support message (spec §21.1), stored in the landlord
 * media table on the private disk.
 *
 * @property int $id
 * @property int $platform_support_message_id
 */
class PlatformSupportMessageAttachment extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $connection = 'landlord';

    protected $fillable = ['platform_support_message_id'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachment')->useDisk('local')->singleFile();
    }

    /**
     * @return BelongsTo<PlatformSupportMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(PlatformSupportMessage::class, 'platform_support_message_id');
    }
}
