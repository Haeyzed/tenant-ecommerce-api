<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Messaging\Support\ChannelTransports;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Support\AudienceResolver;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Settings\Services\TenantSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a catalog notification (spec §17.3, §17.5). The key determines the
 * scope, so a landlord notification triggered from a tenant request still
 * reads the landlord template.
 */
final class NotificationDispatchService
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly NotificationTemplateService $templates,
        private readonly NotificationPreferenceService $preferences,
        private readonly AudienceResolver $audiences,
        private readonly ChannelTransports $transports,
    ) {}

    /**
     * @param  mixed  $notifiable  explicit recipient(s), or the triggering record
     * @param  array<string, mixed>  $variables
     * @param  list<string>|null  $channels  null = the template's channel matrix
     * @param  array<string, mixed>  $data  non-sensitive payload for the inbox and push
     */
    public function dispatch(string $templateKey, mixed $notifiable, array $variables = [], ?array $channels = null, array $data = []): void
    {
        $scope = $this->catalog->scopeOf($templateKey);
        [$audience, $matrix, $isActive, $isMandatory] = $this->template($templateKey, $scope);

        // §17.3 step 1: the tenant master kill switch.
        if ($scope === NotificationScope::Tenant && ! $isMandatory
            && ! (bool) app(TenantSettingsService::class)->get('notifications_enabled', true)) {
            return;
        }

        // Step 2 (mandatory templates can never be inactive).
        if (! $isActive && ! $isMandatory) {
            return;
        }

        // Step 3: only channels enabled in the matrix.
        $enabled = array_keys(array_filter($matrix));
        $candidates = $channels === null ? $enabled : array_values(array_intersect($channels, $enabled));

        if ($candidates === []) {
            return;
        }

        $recipients = $this->audiences->resolve($templateKey, $audience, $notifiable);

        if ($recipients === []) {
            return;
        }

        $content = $this->templates->render($templateKey, $variables, $scope);

        foreach ($recipients as $recipient) {
            $recipientChannels = $candidates;

            // Step 4: the recipient's own preferences (tenant staff and customers).
            if (! $isMandatory && $scope === NotificationScope::Tenant && $recipient instanceof Model
                && in_array($recipient->getMorphClass(), ['user', 'customer'], true)) {
                $recipientChannels = $this->preferences->getEnabledChannels($recipient, $templateKey, $recipientChannels);
            }

            // Step 5: a configured transport and a route for the recipient.
            $recipientChannels = array_values(array_filter(
                $recipientChannels,
                fn (string $channel): bool => $this->transports->canDeliver($channel, $scope, $recipient),
            ));

            if ($recipientChannels === []) {
                continue;
            }

            Notification::send($recipient, new TemplatedNotification(
                $templateKey,
                $scope,
                $content['subject'],
                $content['body'],
                $recipientChannels,
                $data,
            ));
        }
    }

    /**
     * @return array{0: list<string>, 1: array<string, bool>, 2: bool, 3: bool}
     */
    private function template(string $key, NotificationScope $scope): array
    {
        $template = NotificationTemplate::forScope($scope)->with('channels')->where('key', $key)->first();

        if ($template !== null) {
            return [$template->target_audience, $template->channelMatrix(), $template->is_active, $template->is_mandatory];
        }

        // Not yet seeded (a key added since the last defaults sync): the
        // platform default applies until the sync inserts the row.
        Log::warning('Notification template not seeded; using the catalog default.', ['key' => $key, 'scope' => $scope->value]);

        $definition = $this->catalog->definition($key, $scope);

        return [$definition['audience'], $this->catalog->defaultChannels($key, $scope), true, $definition['mandatory']];
    }
}
