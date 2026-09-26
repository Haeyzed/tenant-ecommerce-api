<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\StorefrontSetting;
use App\Shared\Support\SettingValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Tenant storefront presentation settings (spec §13.5, §13.7). Every key is
 * public; the public config response is assembled by
 * StorefrontConfigService.
 */
final class StorefrontSettingsService
{
    private const string CACHE_KEY = 'storefront_settings:values';

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::remember(self::CACHE_KEY, 3600, static fn (): array => StorefrontSetting::query()
            ->get(['key', 'value', 'type'])
            ->mapWithKeys(static fn (StorefrontSetting $row): array => [$row->key => SettingValue::decode($row->value, $row->type)])
            ->all());

        $values = [];

        foreach ($this->definitions() as $key => $definition) {
            $values[$key] = array_key_exists($key, $stored) ? $stored[$key] : $definition['default'];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $definitions = $this->definitions();
        $unknown = array_diff(array_keys($values), array_keys($definitions));

        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, ['Unknown setting.']));
        }

        $validated = Validator::make(
            $values,
            array_map(static fn (array $definition): array => $definition['rules'], array_intersect_key($definitions, $values)),
        )->validate();

        foreach ((array) ($validated['social_links'] ?? []) as $network => $url) {
            $valid = $url === null || ($network === 'whatsapp'
                ? str_starts_with((string) $url, 'https://wa.me/')
                : str_starts_with((string) $url, 'https://'));

            if (! $valid) {
                throw ValidationException::withMessages(['social_links.'.$network => ['Must be an https URL'.($network === 'whatsapp' ? ' on wa.me.' : '.')]]);
            }
        }

        DB::transaction(function () use ($validated, $definitions): void {
            foreach ($validated as $key => $value) {
                StorefrontSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => SettingValue::encode($value, $definitions[$key]['type']), 'type' => $definitions[$key]['type']],
                );
            }
        });

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        StorefrontConfigService::flush();
    }

    /**
     * @return array<string, array{type: string, default: mixed, rules: list<mixed>}>
     */
    public function definitions(): array
    {
        return (array) config('storefront.settings');
    }
}
