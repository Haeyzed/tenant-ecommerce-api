<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Tenant;

use App\Modules\Auth\Support\CredentialCheck;
use App\Modules\Auth\Support\TokenIssuer;
use App\Modules\Messaging\Support\SmsGatewayFactory;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Services\DriverService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Driver authentication (spec §10.3): phone and PIN, with an SMS one-time
 * code for the first login and PIN resets. The code lives only in the
 * (tenant-prefixed) cache, as a keyed hash, with a short TTL and an attempt
 * cap (Assumption A-18). Responses never disclose whether a phone number
 * belongs to a driver.
 */
final readonly class DriverAuthService
{
    public const int OTP_TTL_MINUTES = 10;

    public const int OTP_MAX_ATTEMPTS = 5;

    public function __construct(
        private SmsGatewayFactory $sms,
        private TenantSettingsService $settings,
    ) {}

    public function requestOtp(string $phone): void
    {
        // Checked before the lookup, so the answer is the same for any number.
        $gateway = $this->sms->forTenant() ?? $this->sms->forPlatform();

        if ($gateway === null) {
            throw new ApiException('sms_unavailable', 'Text messages are not available for this store. Contact the store.', 503);
        }

        $phone = DriverService::normalizePhone($phone);
        $driver = Driver::query()->where('phone', $phone)->where('status', Driver::ACTIVE)->first();

        if ($driver === null) {
            return;
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->key($phone), ['hash' => $this->hash($phone, $code), 'attempts' => 0], now()->addMinutes(self::OTP_TTL_MINUTES));

        $store = (string) ($this->settings->get('store_name') ?: 'the store');
        $gateway->send($phone, "Your {$store} driver code is {$code}. It expires in ".self::OTP_TTL_MINUTES.' minutes.');
    }

    /**
     * Sets the PIN (first login or reset) and revokes existing sessions.
     */
    public function verifyOtpAndSetPin(string $phone, string $otp, string $newPin): Driver
    {
        validator(['otp' => $otp, 'pin' => $newPin], [
            'otp' => ['required', 'digits:6'],
            'pin' => ['required', 'digits_between:4,6'],
        ])->validate();

        $phone = DriverService::normalizePhone($phone);
        $key = $this->key($phone);
        $entry = Cache::get($key);

        if (! is_array($entry) || $entry['attempts'] >= self::OTP_MAX_ATTEMPTS) {
            Cache::forget($key);

            throw ValidationException::withMessages(['otp' => ['The code is invalid or has expired. Request a new one.']]);
        }

        if (! hash_equals((string) $entry['hash'], $this->hash($phone, $otp))) {
            Cache::put($key, ['hash' => $entry['hash'], 'attempts' => $entry['attempts'] + 1], now()->addMinutes(self::OTP_TTL_MINUTES));

            throw ValidationException::withMessages(['otp' => ['The code is invalid or has expired. Request a new one.']]);
        }

        Cache::forget($key);
        $driver = Driver::query()->where('phone', $phone)->where('status', Driver::ACTIVE)->first();

        if ($driver === null) {
            throw ValidationException::withMessages(['otp' => ['The code is invalid or has expired. Request a new one.']]);
        }

        $driver->forceFill(['pin_hash' => Hash::make($newPin), 'phone_verified_at' => $driver->phone_verified_at ?? now()])->save();
        $driver->tokens()->delete();

        return $driver;
    }

    /**
     * @return array{token: string, token_type: string, expires_at: string|null, driver: Driver}
     */
    public function login(string $phone, string $pin, string $device = 'api'): array
    {
        $driver = Driver::query()->where('phone', DriverService::normalizePhone($phone))->first();

        try {
            CredentialCheck::assert($driver, $pin);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['phone' => [__('auth.failed')]]);
        }

        /** @var Driver $driver */
        if (! $driver->isActive()) {
            throw ApiException::forbidden('account_disabled', 'This driver account is disabled.');
        }

        $driver->forceFill(['last_login_at' => now()])->save();

        return TokenIssuer::issue($driver, 'driver', $device) + ['driver' => $driver];
    }

    public function logout(Driver $driver): void
    {
        $driver->currentAccessToken()?->delete();
    }

    private function key(string $phone): string
    {
        return 'driver-otp:'.hash('sha256', $phone);
    }

    private function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, (string) config('app.key'));
    }
}
