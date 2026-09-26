<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Models;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A tenant's request for help from the platform (spec §21.1).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $raised_by_user_id
 * @property string $raised_by_name
 * @property string $raised_by_email
 * @property string $subject
 * @property string $category billing | technical | feature_request | bug | other
 * @property string $status open | pending | resolved | closed
 * @property string $priority low | normal | high | urgent
 * @property int|null $assigned_to
 * @property Carbon|null $last_message_at
 */
class PlatformSupportConversation extends Model
{
    public const array CATEGORIES = ['billing', 'technical', 'feature_request', 'bug', 'other'];

    public const array STATUSES = ['open', 'pending', 'resolved', 'closed'];

    public const array PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'raised_by_user_id', 'raised_by_name', 'raised_by_email', 'subject', 'category', 'status',
        'priority', 'assigned_to', 'last_message_at',
    ];

    protected $casts = [
        'raised_by_user_id' => 'integer',
        'assigned_to' => 'integer',
        'last_message_at' => 'datetime',
    ];

    /**
     * @return HasMany<PlatformSupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(PlatformSupportMessage::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'assigned_to');
    }
}
