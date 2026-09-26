<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the channel matrix (spec §17.2). Inherits the connection of
 * the template it is loaded through.
 *
 * @property int $id
 * @property int $notification_template_id
 * @property string $channel
 * @property bool $enabled
 */
class NotificationTemplateChannel extends Model
{
    protected $fillable = ['notification_template_id', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    /**
     * @return BelongsTo<NotificationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }
}
