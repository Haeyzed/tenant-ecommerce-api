<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Support\DisplayFormat;
use Illuminate\Validation\Rule;

/**
 * Effective display formats per spec §5.10 precedence.
 */
final readonly class DisplayPreferences
{
    public function __construct(private PlatformSettingsService $platform) {}

    /**
     * @param  array<string, mixed>|null  $preferences
     * @return array{date_format: string, time_format: string, timezone: string}
     */
    public function forPlatformUser(?array $preferences): array
    {
        return [
            'date_format' => (string) ($preferences['date_format'] ?? $this->platform->get('default_date_format')),
            'time_format' => (string) ($preferences['time_format'] ?? $this->platform->get('default_time_format')),
            'timezone' => (string) $this->platform->get('default_timezone'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $preferences
     * @return array{date_format: string, time_format: string, timezone: string}
     */
    public function forStaff(?array $preferences): array
    {
        $tenant = app(TenantSettingsService::class);

        return [
            'date_format' => (string) ($preferences['date_format'] ?? $tenant->get('date_format') ?? $this->platform->get('default_date_format')),
            'time_format' => (string) ($preferences['time_format'] ?? $tenant->get('time_format') ?? $this->platform->get('default_time_format')),
            'timezone' => (string) $tenant->get('timezone'),
        ];
    }

    /**
     * Validation of a PATCH .../preferences body: each key nullable (null
     * = inherit).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'date_format' => ['sometimes', 'nullable', Rule::in(DisplayFormat::values())],
            'time_format' => ['sometimes', 'nullable', Rule::in(['24h', '12h'])],
        ];
    }
}
