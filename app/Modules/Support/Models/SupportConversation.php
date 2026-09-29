<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ticket or a live chat (spec §59.1). Guest conversations are bound to
 * the storefront guest token until the guest signs in.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $guest_token
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string $channel
 * @property string|null $subject
 * @property string $status
 * @property string|null $priority
 * @property int|null $assigned_to_user_id
 * @property Carbon|null $last_message_at
 * @property Carbon $created_at
 * @property-read Customer|null $customer
 * @property-read User|null $assignee
 * @property-read Collection<int, SupportMessage> $messages
 */
class SupportConversation extends Model
{
    public const string TICKET = 'ticket';

    public const string CHAT = 'chat';

    public const array CHANNELS = [self::TICKET, self::CHAT];

    public const string OPEN = 'open';

    public const string PENDING = 'pending';

    public const string RESOLVED = 'resolved';

    public const string CLOSED = 'closed';

    public const array STATUSES = [self::OPEN, self::PENDING, self::RESOLVED, self::CLOSED];

    public const array PRIORITIES = ['low', 'normal', 'high'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $hidden = ['guest_token'];

    protected $casts = [
        'customer_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'last_message_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }
}
