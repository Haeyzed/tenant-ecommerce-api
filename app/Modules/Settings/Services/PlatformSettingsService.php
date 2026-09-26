<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Models\PlatformSetting;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Metrics\CurrencyTotals;
use App\Shared\Support\SettingValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Platform-wide configuration (spec §13.2, §13.7). Every key is declared in
 * config/platform_settings.php; reads are cached in the landlord store and
 * busted by every write (Principle 9).
 */
final class PlatformSettingsService
{
    private const string CACHE_KEY = 'platform_settings:values';

    public function __construct(private readonly NotificationDispatchService $notifications) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->values();

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * The platform business timezone for dashboards and daily jobs (§5.10).
     */
    public function timezone(): string
    {
        return (string) ($this->get('default_timezone') ?: 'UTC');
    }

    /**
     * Per-currency money reporting with estimated combined totals from
     * the hand-maintained reporting_exchange_rates (§22.5).
     */
    public function reportingTotals(): CurrencyTotals
    {
        return new CurrencyTotals(
            strtoupper((string) ($this->get('default_currency') ?: 'USD')),
            array_change_key_case((array) ($this->get('reporting_exchange_rates') ?? []), CASE_UPPER),
        );
    }

    /**
     * Every key with its effective value (stored value, else the default).
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        /** @var array<string, mixed> $values */
        $values = Cache::store('landlord')->remember(self::CACHE_KEY, 3600, function (): array {
            $stored = PlatformSetting::query()->get(['key', 'value', 'type'])->keyBy('key');
            $values = [];

            foreach ($this->definitions() as $key => $definition) {
                $row = $stored->get($key);
                $values[$key] = $row !== null
                    ? SettingValue::decode($row->value, (string) $row->type)
                    : $definition['default'];
            }

            return $values;
        });

        return $values;
    }

    /**
     * Keys of one group with value, type, default and public flag.
     *
     * @return array<string, array{value: mixed, type: string, default: mixed, public: bool, reason_required: bool, own_route: bool}>
     */
    public function group(string $group): array
    {
        $definitions = $this->groupDefinitions($group);
        $values = $this->values();

        $result = [];

        foreach ($definitions as $key => $definition) {
            $result[$key] = [
                'value' => $values[$key],
                'type' => $definition['type'],
                'default' => $definition['default'],
                'public' => $definition['public'],
                'reason_required' => $definition['reason'],
                'own_route' => $definition['own_route'],
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        return array_keys((array) config('platform_settings'));
    }

    /**
     * Validate and write the given keys of one group (spec §13.7).
     *
     * @param  array<string, mixed>  $values
     */
    public function updateGroup(string $group, array $values, PlatformUser $by, ?string $reason = null): void
    {
        $definitions = $this->groupDefinitions($group);

        $unknown = array_diff(array_keys($values), array_keys($definitions));

        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                array_map(static fn (string $key): string => 'values.'.$key, $unknown),
                ['This setting does not belong to the group.'],
            ));
        }

        foreach (array_keys($values) as $key) {
            if ($definitions[$key]['own_route']) {
                throw ValidationException::withMessages([
                    'values.'.$key => ['This setting is changed through its own route.'],
                ]);
            }
        }

        $rules = [];

        foreach ($values as $key => $value) {
            $rules[$key] = $definitions[$key]['rules'];
        }

        $validated = Validator::make($values, $rules)->validate();

        $current = $this->values();
        $changed = array_filter(
            $validated,
            static fn (mixed $value, string $key): bool => $current[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertInvariants($group, array_merge($current, $validated));

        $needsReason = array_filter(array_keys($changed), static fn (string $key): bool => $definitions[$key]['reason']);

        if ($needsReason !== [] && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => ['A reason is required to change: '.implode(', ', $needsReason).'.']]);
        }

        DB::connection('landlord')->transaction(function () use ($group, $changed, $definitions): void {
            foreach ($changed as $key => $value) {
                $this->write($group, $key, $value, $definitions[$key]['type']);
            }
        });

        $this->flush();

        if ($changed !== []) {
            ActivityRecorder::landlord('platform_settings', "Updated platform settings ({$group})", null, [
                'group' => $group,
                'reason' => $reason,
                'changes' => array_map(
                    static fn (string $key): array => ['old' => $current[$key], 'new' => $changed[$key]],
                    array_combine(array_keys($changed), array_keys($changed)),
                ),
            ], $by);

            $this->notifyOnboardingPaused($changed, $reason);
        }
    }

    /**
     * Write one key directly. Used by the services that own a key changed
     * through its own route (for example billing_payment_mode, §15.10).
     */
    public function set(string $key, mixed $value): void
    {
        $definition = $this->definition($key);

        if ($definition === null) {
            throw ApiException::unprocessable('unknown_setting', "Unknown platform setting [{$key}].");
        }

        $this->write($definition['group'], $key, $value, $definition['type']);
        $this->flush();
    }

    /**
     * Switching tenant registration or provisioning off alerts every
     * super-admin, so a forgotten switch is noticed (spec §9.9).
     *
     * @param  array<string, mixed>  $changed
     */
    private function notifyOnboardingPaused(array $changed, ?string $reason): void
    {
        $paused = array_keys(array_filter(
            array_intersect_key($changed, array_flip(['tenant_registration_enabled', 'tenant_provisioning_enabled'])),
            static fn (mixed $value): bool => $value === false,
        ));

        if ($paused === []) {
            return;
        }

        $superAdmins = PlatformUser::query()->withPlatformRole('super-admin')->where('is_active', true)->get();

        foreach ($superAdmins as $admin) {
            $this->notifications->dispatch('platform.onboarding_paused', $admin, [
                'settings' => implode(', ', $paused),
                'reason' => (string) $reason,
            ]);
        }
    }

    public function isCommissionEnabled(): bool
    {
        return (bool) $this->get('commission_enabled', false);
    }

    /**
     * Every key marked public (spec §13.2, GET /api/platform/config).
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $values = $this->values();

        return array_filter(
            $values,
            fn (string $key): bool => (bool) ($this->definitions()[$key]['public'] ?? false),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array{group: string, type: string, default: mixed, rules: list<mixed>, public: bool, reason: bool, own_route: bool}|null
     */
    public function definition(string $key): ?array
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * @return array<string, array{group: string, type: string, default: mixed, rules: list<mixed>, public: bool, reason: bool, own_route: bool}>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ((array) config('platform_settings') as $group => $keys) {
            foreach ((array) $keys as $key => $definition) {
                $definitions[$key] = array_merge($definition, ['group' => $group]);
            }
        }

        return $definitions;
    }

    public function flush(): void
    {
        Cache::store('landlord')->forget(self::CACHE_KEY);
    }

    /**
     * Inserts every absent key with its default; never overwrites (spec
     * §7.6). billing_payment_mode starts live only in production.
     *
     * @return int the number of keys inserted
     */
    public function seedDefaults(): int
    {
        $existing = PlatformSetting::query()->pluck('key')->all();
        $inserted = 0;

        foreach ($this->definitions() as $key => $definition) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            $default = $key === 'billing_payment_mode'
                ? (app()->isProduction() ? 'live' : 'test')
                : $definition['default'];

            PlatformSetting::query()->create([
                'group' => $definition['group'],
                'key' => $key,
                'value' => SettingValue::encode($default, $definition['type']),
                'type' => $definition['type'],
            ]);
            $inserted++;
        }

        $this->flush();

        return $inserted;
    }

    /**
     * @return array<string, array{group: string, type: string, default: mixed, rules: list<mixed>, public: bool, reason: bool, own_route: bool}>
     */
    private function groupDefinitions(string $group): array
    {
        if (! array_key_exists($group, (array) config('platform_settings'))) {
            abort(404);
        }

        return array_filter($this->definitions(), static fn (array $definition): bool => $definition['group'] === $group);
    }

    private function write(string $group, string $key, mixed $value, string $type): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['group' => $group, 'value' => SettingValue::encode($value, $type), 'type' => $type],
        );
    }

    /**
     * Rules that span keys of one group (spec §13.2).
     *
     * @param  array<string, mixed>  $values
     */
    private function assertInvariants(string $group, array $values): void
    {
        $errors = [];

        if ($group === 'custom_domains') {
            foreach (['custom_domain_ipv4_addresses' => FILTER_FLAG_IPV4, 'custom_domain_ipv6_addresses' => FILTER_FLAG_IPV6] as $key => $flag) {
                foreach ((array) ($values[$key] ?? []) as $address) {
                    if (filter_var($address, FILTER_VALIDATE_IP, $flag | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                        $errors['values.'.$key][] = "[{$address}] is not a public address.";
                    }
                }
            }

            if ($values['custom_domains_enabled'] === true
                && empty($values['custom_domain_cname_target'])
                && empty($values['custom_domain_ipv4_addresses'])) {
                $errors['values.custom_domains_enabled'][] = 'Set a CNAME target or at least one IPv4 address before enabling custom domains.';
            }
        }

        if ($group === 'affiliates') {
            foreach ((array) $values['affiliate_minimum_payout'] as $currency => $amount) {
                if (! is_string($currency) || strlen($currency) !== 3 || ! is_numeric($amount) || $amount < 0) {
                    $errors['values.affiliate_minimum_payout'][] = 'Use {"CUR": amount} with 3-letter currency codes and non-negative amounts.';

                    break;
                }
            }
        }

        if ($group === 'billing') {
            foreach ((array) ($values['reporting_exchange_rates'] ?? []) as $currency => $rate) {
                if (! is_string($currency) || strlen($currency) !== 3 || ! is_numeric($rate) || $rate <= 0) {
                    $errors['values.reporting_exchange_rates'][] = 'Use {"CUR": rate} with 3-letter currency codes and positive rates.';

                    break;
                }
            }
        }

        if ($group === 'general') {
            foreach ((array) ($values['social_links'] ?? []) as $network => $url) {
                if ($url !== null && ! str_starts_with((string) $url, 'https://')) {
                    $errors['values.social_links'][] = "The {$network} link must be an https URL.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
