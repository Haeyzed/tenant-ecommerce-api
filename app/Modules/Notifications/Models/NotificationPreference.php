<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A staff user's or customer's opt-out (or explicit opt-in) for a channel,
 * optionally for one template (spec §17.2). Tenant database only.
 *
 * @property int $id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property string|null $template_key
 * @property string $channel
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['notifiable_type', 'notifiable_id', 'template_key', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
