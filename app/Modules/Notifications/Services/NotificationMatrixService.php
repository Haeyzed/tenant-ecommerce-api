<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The event-by-channel matrix and audiences (spec §17.2, §17.5), for the
 * scope of the current context.
 */
final class NotificationMatrixService
{
    /**
     * @return list<array{key: string, is_mandatory: bool, is_active: bool, target_audience: list<string>, channels: array<string, bool>}>
     */
    public function listMatrix(?NotificationScope $scope = null): array
    {
        return NotificationTemplate::forScope($scope ?? NotificationScope::current())
            ->with('channels')
            ->orderBy('key')
            ->get()
            ->map(static function (NotificationTemplate $template): array {
                $matrix = $template->channelMatrix();

                return [
                    'key' => $template->key,
                    'is_mandatory' => $template->is_mandatory,
                    'is_active' => $template->is_active,
                    'target_audience' => $template->target_audience,
                    'channels' => array_merge(array_fill_keys(NotificationChannel::values(), false), $matrix),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, bool>  $channels
     */
    public function updateChannels(string $templateKey, array $channels, ?NotificationScope $scope = null): void
    {
        $scope ??= NotificationScope::current();

        validator(['channels' => $channels], [
            'channels' => ['required', 'array:'.implode(',', NotificationChannel::values())],
            'channels.*' => ['boolean'],
        ])->validate();

        $template = NotificationTemplate::forScope($scope)->with('channels')->where('key', $templateKey)->firstOrFail();
        $resulting = array_merge($template->channelMatrix(), array_map(boolval(...), $channels));

        if ($template->is_mandatory && ! in_array(true, $resulting, true)) {
            throw ValidationException::withMessages(['channels' => ['A mandatory notification must keep at least one channel.']]);
        }

        DB::connection($scope->connection())->transaction(static function () use ($template, $channels): void {
            foreach ($channels as $channel => $enabled) {
                $template->channels()->updateOrCreate(['channel' => $channel], ['enabled' => (bool) $enabled]);
            }
        });
    }

    /**
     * @param  list<string>  $audience
     */
    public function updateAudience(string $templateKey, array $audience, ?NotificationScope $scope = null): void
    {
        $scope ??= NotificationScope::current();

        validator(['audience' => $audience], [
            'audience' => ['required', 'array', 'min:1'],
            'audience.*' => ['string', 'distinct', Rule::in($scope->audiences())],
        ])->validate();

        NotificationTemplate::forScope($scope)
            ->where('key', $templateKey)
            ->firstOrFail()
            ->forceFill(['target_audience' => array_values($audience)])
            ->save();
    }
}
