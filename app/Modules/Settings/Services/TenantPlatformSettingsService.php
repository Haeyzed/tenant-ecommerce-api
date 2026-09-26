<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\TenantPlatformSetting;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Support\SettingValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Per-tenant settings the landlord reads or enforces (spec §13.3). Written
 * by platform users only; read from the tenant context under §6.4.
 */
final class TenantPlatformSettingsService
{
    /**
     * @var array<string, array{type: string, rules: list<string>}>
     */
    public const array KEYS = [
        'commission_rate' => ['type' => 'decimal', 'rules' => ['nullable', 'numeric', 'min:0', 'max:100']],
    ];

    public function __construct(private readonly PlatformSettingsService $platform) {}

    public function get(Tenant $tenant, string $key, mixed $default = null): mixed
    {
        $values = $this->values($tenant);

        return $values[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(Tenant $tenant): array
    {
        /** @var array<string, mixed> $values */
        $values = Cache::store('landlord')->remember($this->cacheKey($tenant), 3600, static function () use ($tenant): array {
            $stored = TenantPlatformSetting::query()->where('tenant_id', $tenant->getTenantKey())->get()->keyBy('key');

            $values = [];

            foreach (self::KEYS as $key => $definition) {
                $row = $stored->get($key);
                $values[$key] = $row !== null ? SettingValue::decode($row->value, $row->type) : null;
            }

            return $values;
        });

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(Tenant $tenant, array $values): void
    {
        $unknown = array_diff(array_keys($values), array_keys(self::KEYS));

        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, ['Unknown setting.']));
        }

        validator($values, array_map(static fn (array $definition): array => $definition['rules'], array_intersect_key(self::KEYS, $values)))->validate();

        foreach ($values as $key => $value) {
            TenantPlatformSetting::query()->updateOrCreate(
                ['tenant_id' => $tenant->getTenantKey(), 'key' => $key],
                ['value' => SettingValue::encode($value, self::KEYS[$key]['type']), 'type' => self::KEYS[$key]['type']],
            );
        }

        Cache::store('landlord')->forget($this->cacheKey($tenant));
    }

    /**
     * The platform commission rate for the tenant, or null when commission is
     * switched off (spec §13.7, §15.8).
     */
    public function getEffectiveCommissionRate(Tenant $tenant): ?string
    {
        if (! $this->platform->isCommissionEnabled()) {
            return null;
        }

        $override = $this->get($tenant, 'commission_rate');

        return (string) ($override ?? $this->platform->get('default_commission_rate', '0'));
    }

    private function cacheKey(Tenant $tenant): string
    {
        return 'tenant_platform_settings:'.$tenant->getTenantKey();
    }
}
