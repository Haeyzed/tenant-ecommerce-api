<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Template content (spec §17.4, §17.5). Every method acts on the scope it
 * is given, defaulting to the scope of the current context.
 */
final class NotificationTemplateService
{
    public function __construct(private readonly NotificationCatalog $catalog) {}

    public function getTemplate(string $key, ?NotificationScope $scope = null): ?NotificationTemplate
    {
        return NotificationTemplate::forScope($scope ?? NotificationScope::current())
            ->with('channels')
            ->where('key', $key)
            ->first();
    }

    /**
     * @param  array{search?: string|null, audience?: string|null, is_active?: bool|null}  $filters
     * @return Collection<int, NotificationTemplate>
     */
    public function listTemplates(array $filters = [], ?NotificationScope $scope = null): Collection
    {
        return NotificationTemplate::forScope($scope ?? NotificationScope::current())
            ->with('channels')
            ->when($filters['search'] ?? null, static fn ($q, string $search) => $q->where('key', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($filters['audience'] ?? null, static fn ($q, string $audience) => $q->whereJsonContains('target_audience', $audience))
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('key')
            ->get();
    }

    /**
     * Subject and body with placeholders substituted. Unknown placeholders
     * are left untouched and logged by name only (spec §17.8).
     *
     * @param  array<string, mixed>  $variables
     * @return array{subject: string|null, body: string}
     */
    public function render(string $key, array $variables, ?NotificationScope $scope = null): array
    {
        $scope ??= NotificationScope::current();
        $template = $this->getTemplate($key, $scope);

        if ($template === null) {
            $definition = $this->catalog->definition($key, $scope);
            $subject = $definition['subject'];
            $body = $definition['body'];
        } else {
            $subject = $template->subject;
            $body = $template->body;
        }

        return $this->renderContent($key, $subject, $body, $variables + $this->globalVariables($scope));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string|null, body: string}
     */
    public function renderContent(string $key, ?string $subject, string $body, array $variables): array
    {
        $unknown = [];

        $replace = static function (string $text) use ($variables, &$unknown): string {
            return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/', static function (array $match) use ($variables, &$unknown): string {
                if (! array_key_exists($match[1], $variables)) {
                    $unknown[] = $match[1];

                    return $match[0];
                }

                $value = $variables[$match[1]];

                return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
            }, $text);
        };

        $rendered = [
            'subject' => $subject === null ? null : $replace($subject),
            'body' => $replace($body),
        ];

        if ($unknown !== []) {
            Log::warning('Notification rendered with unknown placeholders.', [
                'key' => $key,
                'placeholders' => array_values(array_unique($unknown)),
            ]);
        }

        return $rendered;
    }

    /**
     * @param  array{subject?: string|null, body?: string, is_active?: bool}  $data
     */
    public function updateTemplate(string $key, array $data, ?NotificationScope $scope = null): NotificationTemplate
    {
        $template = $this->findOrFail($key, $scope);

        if (($data['is_active'] ?? true) === false && $template->is_mandatory) {
            throw ValidationException::withMessages(['is_active' => ['A mandatory notification cannot be deactivated.']]);
        }

        $content = array_intersect_key($data, array_flip(['subject', 'body']));

        $template->fill($content + array_intersect_key($data, ['is_active' => true]));

        if ($content !== []) {
            $template->is_customized = true;
        }

        $template->save();

        return $template->refresh()->load('channels');
    }

    public function resetToDefault(string $key, ?NotificationScope $scope = null): NotificationTemplate
    {
        $scope ??= NotificationScope::current();
        $template = $this->findOrFail($key, $scope);
        $definition = $this->catalog->definition($key, $scope);

        $template->forceFill([
            'subject' => $definition['subject'],
            'body' => $definition['body'],
            'is_customized' => false,
        ])->save();

        return $template->refresh()->load('channels');
    }

    /**
     * Inserts the missing template and channel rows of a scope; never
     * overwrites (spec §17.4). Runs at provisioning and on every defaults
     * sync for the tenant scope, and from the landlord seeders.
     */
    public function seedDefaults(NotificationScope $scope): int
    {
        $inserted = 0;

        DB::connection($scope->connection())->transaction(function () use ($scope, &$inserted): void {
            $existing = NotificationTemplate::forScope($scope)->with('channels')->get()->keyBy('key');

            foreach ($this->catalog->definitions($scope) as $key => $definition) {
                /** @var NotificationTemplate|null $template */
                $template = $existing->get($key);

                if ($template === null) {
                    $template = NotificationTemplate::forScope($scope)->create([
                        'key' => $key,
                        'subject' => $definition['subject'],
                        'body' => $definition['body'],
                        'target_audience' => $definition['audience'],
                        'is_active' => true,
                        'is_mandatory' => $definition['mandatory'],
                        'is_customized' => false,
                    ]);
                    $template->setRelation('channels', new Collection);
                    $inserted++;
                }

                $present = $template->channels->pluck('channel')->all();

                foreach ($this->catalog->defaultChannels($key, $scope) as $channel => $enabled) {
                    if (! in_array($channel, $present, true)) {
                        $template->channels()->create(['channel' => $channel, 'enabled' => $enabled]);
                    }
                }
            }
        });

        return $inserted;
    }

    private function findOrFail(string $key, ?NotificationScope $scope): NotificationTemplate
    {
        return NotificationTemplate::forScope($scope ?? NotificationScope::current())
            ->where('key', $key)
            ->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function globalVariables(NotificationScope $scope): array
    {
        $platformName = (string) app(PlatformSettingsService::class)->get('platform_name', config('app.name'));

        if ($scope === NotificationScope::Landlord || ! tenancy()->initialized) {
            return ['platform_name' => $platformName];
        }

        return [
            'platform_name' => $platformName,
            'store_name' => (string) app(TenantSettingsService::class)->get('store_name'),
        ];
    }
}
