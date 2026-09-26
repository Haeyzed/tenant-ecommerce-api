<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Models\Driver;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * In-house delivery drivers (spec §36.3). Deactivation revokes the driver's
 * sessions. Delivery lists arrive with shipments.
 */
final readonly class DriverService
{
    /**
     * @param  array{search?: string, status?: string, is_available?: bool}  $filters
     * @return Collection<int, Driver>
     */
    public function listDrivers(array $filters = []): Collection
    {
        return Driver::query()
            ->when(filled($filters['search'] ?? null), static fn ($q) => $q->where(static fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%')
                ->orWhere('phone', self::normalizePhone((string) $filters['search']))))
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when(array_key_exists('is_available', $filters), static fn ($q) => $q->where('is_available', (bool) $filters['is_available']))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Driver>
     */
    public function listAvailableDrivers(): Collection
    {
        return Driver::query()->where('status', Driver::ACTIVE)->where('is_available', true)->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDriver(array $data): Driver
    {
        $driver = new Driver($this->validate($data, null));
        $driver->status = Driver::ACTIVE;
        $driver->save();

        return $driver;
    }

    /**
     * A changed phone number must be verified again by OTP.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDriver(Driver $driver, array $data): Driver
    {
        $validated = $this->validate($data, $driver);
        $driver->fill($validated);

        if ($driver->isDirty('phone')) {
            $driver->forceFill(['phone_verified_at' => null, 'pin_hash' => null]);
            $driver->tokens()->delete();
        }

        $driver->save();

        return $driver;
    }

    public function deactivateDriver(Driver $driver): Driver
    {
        $driver->forceFill(['status' => Driver::INACTIVE, 'is_available' => false])->save();
        $driver->tokens()->delete();

        return $driver;
    }

    public function setAvailability(Driver $driver, bool $isAvailable): Driver
    {
        if ($isAvailable && ! $driver->isActive()) {
            throw ValidationException::withMessages(['is_available' => ['An inactive driver cannot be available.']]);
        }

        $driver->forceFill(['is_available' => $isAvailable])->save();

        return $driver;
    }

    /**
     * Digits with a leading "+" when one was given; spaces, dashes, dots and
     * brackets removed.
     */
    public static function normalizePhone(string $phone): string
    {
        $trimmed = trim($phone);

        return (str_starts_with($trimmed, '+') ? '+' : '').preg_replace('/\D+/', '', $trimmed);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?Driver $existing): array
    {
        if (isset($data['phone']) && is_string($data['phone'])) {
            $data['phone'] = self::normalizePhone($data['phone']);
        }

        $req = $existing === null ? 'required' : 'sometimes';

        return validator($data, [
            'name' => [$req, 'string', 'max:120'],
            'phone' => [$req, 'string', 'regex:/^\+?[0-9]{7,15}$/', Rule::unique('tenant.drivers', 'phone')->ignore($existing?->id)],
            'vehicle_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.users', 'id')->whereNull('deleted_at'), Rule::unique('tenant.drivers', 'user_id')->ignore($existing?->id)],
        ])->validate();
    }
}
