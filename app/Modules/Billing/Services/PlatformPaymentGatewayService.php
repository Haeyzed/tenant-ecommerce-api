<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Models\PlatformPaymentGateway;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\GatewayCredentials;
use App\Shared\Payments\PaymentGatewayFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The platform's own billing credentials (spec §15.9, §15.10). Secrets are
 * write-only; an omitted secret keeps the stored one.
 */
final readonly class PlatformPaymentGatewayService
{
    public function __construct(
        private PaymentGatewayFactory $factory,
        private PlatformSettingsService $settings,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * Every provider and mode, configured or not, secrets masked.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = PlatformPaymentGateway::query()->get()->keyBy(static fn (PlatformPaymentGateway $row): string => $row->provider.':'.$row->mode);
        $result = [];

        foreach (PlatformPaymentGateway::PROVIDERS as $provider) {
            foreach (['test', 'live'] as $mode) {
                $result[] = $this->present($provider, $mode, $rows->get($provider.':'.$mode));
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(string $provider, string $mode, array $data, PlatformUser $by): PlatformPaymentGateway
    {
        $this->assertKnown($provider, $mode);

        if ($mode === 'live' && ! PaymentGatewayFactory::liveAllowed()) {
            throw ApiException::forbidden('live_payments_disabled', 'Live credentials cannot be saved in this environment.');
        }

        $row = PlatformPaymentGateway::query()->where('provider', $provider)->where('mode', $mode)->first();

        /** @var array<string, mixed> $validated */
        $validated = validator($data, [
            'public_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret_key' => [$row === null ? 'required' : 'sometimes', 'string', 'max:1024'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'extra_credentials' => ['sometimes', 'nullable', 'array'],
            'extra_credentials.*' => ['string', 'max:1024'],
            'supported_currencies' => [$row === null ? 'required' : 'sometimes', 'array', 'min:1'],
            'supported_currencies.*' => ['string', 'size:3', 'distinct', Rule::exists('landlord.currencies', 'code')],
            'supported_country_ids' => ['sometimes', 'nullable', 'array'],
            'supported_country_ids.*' => ['integer', 'distinct', Rule::exists('landlord.countries', 'id')],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ])->validate();

        if (isset($validated['supported_currencies'])) {
            $validated['supported_currencies'] = array_values(array_map('strtoupper', $validated['supported_currencies']));
        }

        $row ??= new PlatformPaymentGateway(['provider' => $provider, 'mode' => $mode, 'is_enabled' => false, 'is_default' => false]);
        $secretsChanged = array_intersect_key($validated, array_flip(['secret_key', 'webhook_secret', 'extra_credentials', 'public_key'])) !== [];

        $row->fill($validated + ['updated_by' => $by->id]);

        if ($secretsChanged) {
            $result = $this->validateRow($row);
            $row->credentials_verified_at = now();

            ActivityRecorder::landlord('payment_gateways', "Credentials saved for {$provider} ({$mode})", $row, ['detected_mode' => $result['detected_mode']], $by);
        }

        $row->save();

        return $row;
    }

    /**
     * @return array{valid: bool, detected_mode: string|null, message: string}
     */
    public function test(string $provider, string $mode): array
    {
        $row = $this->find($provider, $mode);
        $result = $this->factory->forPlatform($provider, $mode)->validateCredentials();

        if ($result['valid'] && ($result['detected_mode'] === null || $result['detected_mode'] === $mode)) {
            $row->forceFill(['credentials_verified_at' => now()])->save();
        }

        return $result;
    }

    public function enable(string $provider, string $mode, PlatformUser $by): PlatformPaymentGateway
    {
        $row = $this->find($provider, $mode);

        if ($row->credentials_verified_at === null || $row->credentials_verified_at->lt(now()->subDay())) {
            throw ApiException::unprocessable('gateway_not_verified', 'Test the credentials successfully before enabling the gateway.');
        }

        $row->forceFill(['is_enabled' => true, 'updated_by' => $by->id])->save();
        ActivityRecorder::landlord('payment_gateways', "Gateway {$provider} ({$mode}) enabled", $row, [], $by);

        return $row;
    }

    /**
     * Stops new checkouts only; existing subscriptions keep using it.
     */
    public function disable(string $provider, string $mode, PlatformUser $by): PlatformPaymentGateway
    {
        $row = $this->find($provider, $mode);
        $row->forceFill(['is_enabled' => false, 'is_default' => false, 'updated_by' => $by->id])->save();
        ActivityRecorder::landlord('payment_gateways', "Gateway {$provider} ({$mode}) disabled", $row, [], $by);

        return $row;
    }

    public function setDefault(string $provider, string $mode, PlatformUser $by): PlatformPaymentGateway
    {
        return DB::connection('landlord')->transaction(function () use ($provider, $mode, $by): PlatformPaymentGateway {
            $row = PlatformPaymentGateway::query()->where('provider', $provider)->where('mode', $mode)->lockForUpdate()->firstOrFail();

            if (! $row->is_enabled) {
                throw ApiException::unprocessable('gateway_not_enabled', 'Only an enabled gateway can be the default.');
            }

            PlatformPaymentGateway::query()->where('mode', $mode)->where('id', '!=', $row->id)->update(['is_default' => false]);
            $row->forceFill(['is_default' => true, 'updated_by' => $by->id])->save();

            return $row;
        });
    }

    /**
     * Enabled gateways of the current billing mode that can charge the
     * currency for the subject's country, default first (§15.10).
     *
     * @param  Model  $subject  a Tenant or TenantRegistration (country_id)
     * @return Collection<int, PlatformPaymentGateway>
     */
    public function availableFor(Model $subject, string $currency): Collection
    {
        $mode = (string) $this->settings->get('billing_payment_mode');
        $countryId = (int) $subject->getAttribute('country_id');
        $currency = strtoupper($currency);

        return PlatformPaymentGateway::query()
            ->where('mode', $mode)
            ->where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->get()
            ->filter(static fn (PlatformPaymentGateway $row): bool => in_array($currency, $row->supported_currencies, true)
                && ($row->supported_country_ids === null || in_array($countryId, array_map('intval', $row->supported_country_ids), true)))
            ->values()
            ->toBase();
    }

    /**
     * Safeguard 5 of spec §15.9. Affects new subscriptions only.
     */
    public function setBillingMode(string $mode, string $reason, PlatformUser $by): void
    {
        if (! in_array($mode, ['test', 'live'], true)) {
            throw ApiException::unprocessable('validation_failed', 'Unknown payment mode.');
        }

        $previous = (string) $this->settings->get('billing_payment_mode');

        if ($previous === $mode) {
            return;
        }

        if ($mode === 'live') {
            if (! PaymentGatewayFactory::liveAllowed()) {
                throw ApiException::forbidden('live_payments_disabled', 'Live payments are disabled in this environment.');
            }

            $ready = PlatformPaymentGateway::query()->where('mode', 'live')->where('is_enabled', true)->whereNotNull('credentials_verified_at')->exists();

            if (! $ready) {
                throw ApiException::unprocessable('no_live_gateway', 'Enable at least one validated live gateway first.');
            }
        }

        $this->settings->set('billing_payment_mode', $mode);

        ActivityRecorder::landlord('payment_gateways', "Billing payment mode changed to {$mode}", null, [
            'previous_mode' => $previous,
            'mode' => $mode,
            'reason' => $reason,
        ], $by);

        $superAdmins = PlatformUser::query()->withPlatformRole('super-admin')->where('is_active', true)->get();

        $this->notifications->dispatch('platform.billing_mode_changed', $superAdmins, [
            'mode' => $mode,
            'previous_mode' => $previous,
            'changed_by' => $by->name,
            'reason' => $reason,
        ]);
    }

    public function webhookUrl(string $provider, string $mode): string
    {
        return rtrim((string) config('app.url'), '/')."/api/webhooks/{$provider}/{$mode}";
    }

    /**
     * @return array{valid: bool, detected_mode: string|null, message: string}
     */
    private function validateRow(PlatformPaymentGateway $row): array
    {
        $result = $this->factory->forValidation(new GatewayCredentials(
            $row->provider,
            $row->mode,
            $row->secret_key,
            $row->public_key,
            $row->webhook_secret,
            (array) ($row->extra_credentials ?? []),
        ))->validateCredentials();

        if ($result['detected_mode'] !== null && $result['detected_mode'] !== $row->mode) {
            throw ApiException::unprocessable('payment_mode_mismatch', "These are {$result['detected_mode']} credentials; save them in the {$result['detected_mode']} slot.", [
                'detected_mode' => $result['detected_mode'],
            ]);
        }

        if (! $result['valid']) {
            throw ApiException::unprocessable('gateway_credentials_invalid', $result['message']);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $provider, string $mode, ?PlatformPaymentGateway $row): array
    {
        return [
            'provider' => $provider,
            'mode' => $mode,
            'configured' => $row !== null,
            'public_key' => $row?->maskedPublicKey(),
            'has_secret_key' => $row !== null && filled($row->secret_key),
            'has_webhook_secret' => $row !== null && filled($row->webhook_secret),
            'is_enabled' => (bool) $row?->is_enabled,
            'is_default' => (bool) $row?->is_default,
            'sort_order' => $row?->sort_order ?? 0,
            'supported_currencies' => $row?->supported_currencies ?? [],
            'supported_country_ids' => $row?->supported_country_ids,
            'credentials_verified_at' => $row?->credentials_verified_at?->toIso8601String(),
            'last_webhook_at' => $row?->last_webhook_at?->toIso8601String(),
            'webhook_url' => $this->webhookUrl($provider, $mode),
        ];
    }

    private function find(string $provider, string $mode): PlatformPaymentGateway
    {
        $this->assertKnown($provider, $mode);

        return PlatformPaymentGateway::query()->where('provider', $provider)->where('mode', $mode)->firstOrFail();
    }

    private function assertKnown(string $provider, string $mode): void
    {
        if (! in_array($provider, PlatformPaymentGateway::PROVIDERS, true) || ! in_array($mode, ['test', 'live'], true)) {
            abort(404);
        }
    }
}
