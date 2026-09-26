<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Enums\NotificationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One notification event's content and audience (spec §17.2). The same
 * shape exists in the landlord and every tenant database; queries go
 * through forScope() so the database is always explicit.
 *
 * @property int $id
 * @property string $key
 * @property string|null $subject
 * @property string $body
 * @property list<string> $target_audience
 * @property bool $is_active
 * @property bool $is_mandatory
 * @property bool $is_customized
 * @property-read Collection<int, NotificationTemplateChannel> $channels
 */
class NotificationTemplate extends Model
{
    protected $fillable = ['key', 'subject', 'body', 'target_audience', 'is_active', 'is_mandatory', 'is_customized'];

    protected $casts = [
        'target_audience' => 'array',
        'is_active' => 'boolean',
        'is_mandatory' => 'boolean',
        'is_customized' => 'boolean',
    ];

    /**
     * @return Builder<self>
     */
    public static function forScope(NotificationScope $scope): Builder
    {
        return static::on($scope->connection());
    }

    /**
     * @return HasMany<NotificationTemplateChannel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(NotificationTemplateChannel::class);
    }

    /**
     * @return array<string, bool>
     */
    public function channelMatrix(): array
    {
        return $this->channels->mapWithKeys(
            static fn (NotificationTemplateChannel $row): array => [$row->channel => $row->enabled],
        )->all();
    }
}
