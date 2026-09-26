<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Models\TenantSetting;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\SettingValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Tenant business settings (spec §13.4, §13.7). Only keys declared in
 * config/tenant_settings.php may be read or written. Reads apply the
 * documented defaults and the platform ceilings; writes reject values that
 * exceed a ceiling.
 */
final class TenantSettingsService
{
    private const string CACHE_KEY = 'tenant_settings:values';

    public function __construct(
        private readonly PlatformSettingsService $platform,
        private readonly FeatureAccessService $features,
        private readonly TenantPlatformSettingsService $tenantPlatform,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, $this->definitions())) {
            throw new RuntimeException("Unknown tenant setting [{$key}].");
        }

        $value = $this->values()[$key];

        return $value ?? $default;
    }

    /**
     * Every key with its effective value, including read-only values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->values(), [
            'app_version' => (string) config('app.version', '1.0.0'),
            'commission_rate' => $this->tenantPlatform->getEffectiveCommissionRate($this->tenant()),
        ]);
    }

    /**
     * Validate and write settings (spec §13.7).
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $definitions = $this->definitions();

        $errors = [];
        $rules = [];

        foreach ($values as $key => $value) {
            if (! isset($definitions[$key])) {
                $errors[$key][] = 'Unknown setting.';

                continue;
            }

            if ($definitions[$key]['own_route']) {
                $errors[$key][] = 'This setting is changed through its own route.';

                continue;
            }

            $rules[$key] = $definitions[$key]['rules'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated = Validator::make($values, $rules)->validate();

        $this->assertCeilings($validated);

        DB::transaction(function () use ($validated, $definitions): void {
            foreach ($validated as $key => $value) {
                $this->write($key, $value, $definitions[$key]['type']);
            }
        });

        if (array_key_exists('timezone', $validated)) {
            $this->tenant()->forceFill(['timezone' => $validated['timezone']])->saveQuietly();
        }

        $this->flush();
    }

    /**
     * Write one key directly, for services that own a key changed through its
     * own route (payment_mode, default_currency) and for provisioning.
     */
    public function set(string $key, mixed $value): void
    {
        $definition = $this->definitions()[$key] ?? throw new RuntimeException("Unknown tenant setting [{$key}].");

        $this->write($key, $value, $definition['type']);
        $this->flush();
    }

    /**
     * The mailer configuration for tenant email (spec §16.2): the platform
     * mailer, or the decrypted custom configuration when the tenant uses its
     * own provider and holds the custom_email capability.
     *
     * @return array<string, mixed>|null null = use the platform mailer
     */
    public function getMailConfig(): ?array
    {
        if ($this->get('email_provider') !== 'custom' || ! $this->features->tenantCanAccess($this->tenant(), 'custom_email')) {
            return null;
        }

        /** @var array<string, mixed>|null $config */
        $config = $this->get('custom_mail_settings');

        return $config;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        StorefrontConfigService::flush();
    }

    /**
     * @return array<string, array{type: string, default: mixed, rules: list<mixed>, own_route: bool, requires_feature: string|null}>
     */
    public function definitions(): array
    {
        return (array) config('tenant_settings');
    }

    /**
     * @return array<string, mixed>
     */
    private function values(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::remember(self::CACHE_KEY, 3600, static fn (): array => TenantSetting::query()
            ->get(['key', 'value', 'type'])
            ->mapWithKeys(static fn (TenantSetting $row): array => [$row->key => SettingValue::decode($row->value, $row->type)])
            ->all());

        $values = [];

        foreach ($this->definitions() as $key => $definition) {
            $values[$key] = array_key_exists($key, $stored) ? $stored[$key] : $this->resolveDefault($definition['default']);
        }

        // Platform ceilings (spec §13.1 ceiling pattern).
        $values['guest_checkout_enabled'] = $values['guest_checkout_enabled']
            && (bool) $this->platform->get('guest_checkout_allowed_platform_wide', true);

        return $values;
    }

    private function resolveDefault(mixed $default): mixed
    {
        if (! is_string($default)) {
            return $default;
        }

        if (str_starts_with($default, 'tenant:')) {
            return $this->tenant()->getAttribute(substr($default, 7));
        }

        if (str_starts_with($default, 'platform:')) {
            return $this->platform->get(substr($default, 9));
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assertCeilings(array $values): void
    {
        $tenant = $this->tenant();

        foreach ($values as $key => $value) {
            $feature = $this->definitions()[$key]['requires_feature'] ?? null;

            if ($feature !== null && $value !== null && ! $this->features->tenantCanAccess($tenant, $feature)) {
                throw ApiException::forbidden('feature_unavailable', "This setting requires the {$feature} feature.", ['module' => $feature]);
            }
        }

        if (($values['email_provider'] ?? null) === 'custom' && ! $this->features->tenantCanAccess($tenant, 'custom_email')) {
            throw ApiException::forbidden('feature_unavailable', 'A custom email provider requires the custom_email feature.', ['module' => 'custom_email']);
        }
    }

    private function write(string $key, mixed $value, string $type): void
    {
        TenantSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => SettingValue::encode($value, $type), 'type' => $type],
        );
    }

    private function tenant(): Tenant
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Tenant settings are only available in the tenant context.');
        }

        return $tenant;
    }
}
