<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\WhatsAppSetting;
use App\Shared\Exceptions\ApiException;
use App\Shared\Messaging\Contracts\WhatsAppGatewayInterface;
use App\Shared\Messaging\WhatsApp\WhatsAppBusinessGateway;

/**
 * The tenant's WhatsApp number (spec §16.4). Messages go through the
 * tenant's own number when it is active, otherwise through the platform
 * number from the environment.
 */
final class WhatsAppSettingsService
{
    public function getSettings(): WhatsAppSetting
    {
        /** @var WhatsAppSetting $settings */
        $settings = WhatsAppSetting::query()->firstOrCreate([], ['is_active' => false]);

        return $settings;
    }

    /**
     * Changing credentials clears the verification: the new number must be
     * tested again before it is used.
     *
     * @param  array{phone_number_id?: string|null, business_account_id?: string|null, access_token?: string|null}  $data
     */
    public function saveCredentials(array $data): WhatsAppSetting
    {
        /** @var array<string, string|null> $validated */
        $validated = validator($data, [
            'phone_number_id' => ['required', 'string', 'max:64'],
            'business_account_id' => ['nullable', 'string', 'max:64'],
            'access_token' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ])->validate();

        $settings = $this->getSettings();

        if (! array_key_exists('access_token', $validated) || blank($validated['access_token'])) {
            unset($validated['access_token']);
        }

        $settings->fill($validated);

        if ($settings->isDirty(['phone_number_id', 'access_token'])) {
            $settings->forceFill(['verified_at' => null, 'is_active' => false]);
        }

        $settings->save();

        return $settings;
    }

    public function sendTestMessage(string $to): bool
    {
        $settings = $this->getSettings();

        if (! $settings->hasCredentials()) {
            throw ApiException::unprocessable('whatsapp_not_configured', 'Save the WhatsApp credentials first.');
        }

        $sent = $this->ownGateway($settings)->send($to, 'This is a test message from your store. WhatsApp is connected.');

        if ($sent) {
            $settings->forceFill(['verified_at' => now()])->save();
        }

        return $sent;
    }

    public function activate(): WhatsAppSetting
    {
        $settings = $this->getSettings();

        if ($settings->verified_at === null) {
            throw ApiException::unprocessable('whatsapp_not_verified', 'Send a successful test message before activating.');
        }

        $settings->forceFill(['is_active' => true])->save();

        return $settings;
    }

    public function deactivate(): WhatsAppSetting
    {
        $settings = $this->getSettings();
        $settings->forceFill(['is_active' => false])->save();

        return $settings;
    }

    /**
     * The gateway messages use now: the tenant's active number, else the
     * platform number; null when neither is configured.
     */
    public function gateway(): ?WhatsAppGatewayInterface
    {
        $settings = WhatsAppSetting::query()->first();

        if ($settings !== null && $settings->is_active && $settings->hasCredentials()) {
            return $this->ownGateway($settings);
        }

        return self::platformGateway();
    }

    public static function platformGateway(): ?WhatsAppGatewayInterface
    {
        $config = (array) config('services.whatsapp');

        if (blank($config['phone_number_id'] ?? null) || blank($config['access_token'] ?? null)) {
            return null;
        }

        return new WhatsAppBusinessGateway(
            (string) $config['phone_number_id'],
            (string) $config['access_token'],
            (string) $config['base_url'],
            (string) $config['api_version'],
            (int) $config['timeout'],
        );
    }

    private function ownGateway(WhatsAppSetting $settings): WhatsAppGatewayInterface
    {
        $config = (array) config('services.whatsapp');

        return new WhatsAppBusinessGateway(
            (string) $settings->phone_number_id,
            (string) $settings->access_token,
            (string) $config['base_url'],
            (string) $config['api_version'],
            (int) $config['timeout'],
        );
    }
}
