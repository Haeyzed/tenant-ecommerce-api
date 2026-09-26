<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A staff user's or customer's own channel preferences (spec §17.3, §17.5).
 * Tenant context only.
 */
final class NotificationPreferenceService
{
    /**
     * @return Collection<int, NotificationPreference>
     */
    public function getPreferences(Model $notifiable): Collection
    {
        return $this->query($notifiable)->orderBy('template_key')->orderBy('channel')->get();
    }

    /**
     * Applies the opt-out resolution order of spec §17.3 step 4.
     *
     * @param  list<string>  $defaultChannels
     * @return list<string>
     */
    public function getEnabledChannels(Model $notifiable, string $templateKey, array $defaultChannels): array
    {
        $rows = $this->query($notifiable)
            ->where(static fn ($q) => $q->whereNull('template_key')->orWhere('template_key', $templateKey))
            ->get();

        return array_values(array_filter($defaultChannels, static function (string $channel) use ($rows, $templateKey): bool {
            $specific = $rows->first(static fn (NotificationPreference $row): bool => $row->template_key === $templateKey && $row->channel === $channel);

            if ($specific !== null) {
                return $specific->enabled;
            }

            $general = $rows->first(static fn (NotificationPreference $row): bool => $row->template_key === null && $row->channel === $channel);

            return $general?->enabled ?? true;
        }));
    }

    public function setPreference(Model $notifiable, ?string $templateKey, string $channel, bool $enabled): NotificationPreference
    {
        validator(['channel' => $channel], ['channel' => ['required', Rule::enum(NotificationChannel::class)]])->validate();

        if ($templateKey !== null) {
            $template = NotificationTemplate::forScope(NotificationScope::Tenant)->where('key', $templateKey)->first();

            if ($template === null) {
                throw ValidationException::withMessages(['template_key' => ['Unknown notification.']]);
            }

            if ($template->is_mandatory) {
                throw ValidationException::withMessages(['template_key' => ['This notification is mandatory and cannot be turned off.']]);
            }
        }

        /** @var NotificationPreference $preference */
        $preference = NotificationPreference::query()->updateOrCreate([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'template_key' => $templateKey,
            'channel' => $channel,
        ], ['enabled' => $enabled]);

        return $preference;
    }

    public function resetToDefault(Model $notifiable, ?string $templateKey = null, ?string $channel = null): void
    {
        $this->query($notifiable)
            ->when($templateKey !== null, static fn ($q) => $q->where('template_key', $templateKey))
            ->when($channel !== null, static fn ($q) => $q->where('channel', $channel))
            ->delete();
    }

    /**
     * @return Builder<NotificationPreference>
     */
    private function query(Model $notifiable): Builder
    {
        return NotificationPreference::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey());
    }
}
