<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\SmsGatewaySetting;
use App\Modules\Messaging\Support\SmsGatewayFactory;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The tenant's SMS gateways (spec §16.3). Exactly one active gateway is
 * the default whenever any is active.
 */
final class SmsGatewaySettingsService
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function saveGatewayCredentials(string $provider, array $credentials): SmsGatewaySetting
    {
        validator(['provider' => $provider], ['provider' => ['required', Rule::in(SmsGatewaySetting::PROVIDERS)]])->validate();

        $rules = [];

        foreach (SmsGatewayFactory::requiredCredentials($provider) as $key) {
            $rules[$key] = ['required', 'string', 'max:255'];
        }

        $rules['sender_id'] ??= ['nullable', 'string', 'max:32'];

        /** @var array<string, string> $validated */
        $validated = validator($credentials, $rules)->validate();

        /** @var SmsGatewaySetting $setting */
        $setting = SmsGatewaySetting::query()->updateOrCreate(['provider' => $provider], ['credentials' => array_filter($validated, filled(...))]);

        return $setting;
    }

    public function setDefaultGateway(string $provider): void
    {
        DB::connection('tenant')->transaction(function () use ($provider): void {
            $setting = $this->find($provider, lock: true);

            if (! $setting->is_active) {
                throw ApiException::unprocessable('sms_gateway_inactive', 'Activate the gateway before making it the default.');
            }

            SmsGatewaySetting::query()->where('id', '!=', $setting->id)->update(['is_default' => false]);
            $setting->forceFill(['is_default' => true])->save();
        });
    }

    public function activateGateway(string $provider): void
    {
        DB::connection('tenant')->transaction(function () use ($provider): void {
            $setting = $this->find($provider, lock: true);
            $hasDefault = SmsGatewaySetting::query()->where('is_active', true)->where('is_default', true)->exists();

            $setting->forceFill(['is_active' => true, 'is_default' => $setting->is_default || ! $hasDefault])->save();
        });
    }

    public function deactivateGateway(string $provider): void
    {
        DB::connection('tenant')->transaction(function () use ($provider): void {
            $setting = $this->find($provider, lock: true);

            if ($setting->is_default && SmsGatewaySetting::query()->where('id', '!=', $setting->id)->where('is_active', true)->exists()) {
                throw ApiException::unprocessable('sms_gateway_is_default', 'Choose another default gateway before deactivating this one.');
            }

            $setting->forceFill(['is_active' => false, 'is_default' => false])->save();
        });
    }

    public function getDefaultGateway(): ?SmsGatewaySetting
    {
        return SmsGatewaySetting::query()->where('is_active', true)->where('is_default', true)->first();
    }

    /**
     * @return Collection<int, SmsGatewaySetting>
     */
    public function listGateways(): Collection
    {
        return SmsGatewaySetting::query()->orderBy('provider')->get();
    }

    public function find(string $provider, bool $lock = false): SmsGatewaySetting
    {
        return SmsGatewaySetting::query()
            ->where('provider', $provider)
            ->when($lock, static fn ($q) => $q->lockForUpdate())
            ->firstOrFail();
    }
}
