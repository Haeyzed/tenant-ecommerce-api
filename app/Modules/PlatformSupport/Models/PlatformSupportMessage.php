<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One message in a platform support conversation (spec §21.1). Internal
 * notes are for platform staff only and never reach the tenant.
 *
 * @property int $id
 * @property int $platform_support_conversation_id
 * @property string $sender_type tenant | platform_user | system
 * @property int|null $sender_id
 * @property string $sender_label
 * @property string $body
 * @property bool $is_internal_note
 * @property Carbon|null $read_at
 */
class PlatformSupportMessage extends Model
{
    public const string TENANT = 'tenant';

    public const string PLATFORM_USER = 'platform_user';

    public const string SYSTEM = 'system';

    protected $connection = 'landlord';

    protected $fillable = ['platform_support_conversation_id', 'sender_type', 'sender_id', 'sender_label', 'body', 'is_internal_note', 'read_at'];

    protected $casts = [
        'sender_id' => 'integer',
        'is_internal_note' => 'boolean',
        'read_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<PlatformSupportConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PlatformSupportConversation::class, 'platform_support_conversation_id');
    }

    /**
     * @return HasMany<PlatformSupportMessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(PlatformSupportMessageAttachment::class);
    }
}
