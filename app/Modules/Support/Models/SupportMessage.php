<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message in a conversation (spec §59.1). sender_id is customers.id or
 * users.id depending on sender_type, not a morph. Internal notes are staff
 * only and never reach the customer.
 *
 * @property int $id
 * @property int $support_conversation_id
 * @property string $sender_type
 * @property int|null $sender_id
 * @property string $body
 * @property bool $is_internal_note
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 * @property-read SupportConversation $conversation
 * @property-read Collection<int, SupportMessageAttachment> $attachments
 */
class SupportMessage extends Model
{
    public const string CUSTOMER = 'customer';

    public const string GUEST = 'guest';

    public const string STAFF = 'staff';

    public const string SYSTEM = 'system';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'support_conversation_id' => 'integer',
        'sender_id' => 'integer',
        'is_internal_note' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function fromCustomerSide(): bool
    {
        return in_array($this->sender_type, [self::CUSTOMER, self::GUEST], true);
    }

    /**
     * @return BelongsTo<SupportConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'support_conversation_id');
    }

    /**
     * @return HasMany<SupportMessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportMessageAttachment::class);
    }
}
