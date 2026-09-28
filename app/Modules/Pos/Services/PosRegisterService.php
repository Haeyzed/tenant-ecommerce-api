<?php

declare(strict_types=1);

namespace App\Modules\Pos\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Terminals\PosTerminalGatewayFactory;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Registers (spec §51.1, §51.8). The plan's max_pos_registers limit is
 * enforced by the route (usage.limit); the per-warehouse ceiling here.
 */
final readonly class PosRegisterService
{
    public function __construct(private PlatformSettingsService $platformSettings) {}

    /**
     * Active registers, the plan limit's counter (§11.8).
     */
    public static function countActive(): int
    {
        return PosRegister::query()->where('is_active', true)->count();
    }

    /**
     * @param  array<string, mixed>  $data  warehouse_id, name
     */
    public function createRegister(array $data): PosRegister
    {
        $validated = Validator::make($data, [
            'warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'name' => ['required', 'string', 'max:120'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($validated): PosRegister {
            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($validated['warehouse_id']);
            $this->assertWarehouseCeiling($warehouse);

            $register = new PosRegister;
            $register->forceFill(['warehouse_id' => $warehouse->id, 'name' => $validated['name'], 'is_active' => true])->save();

            return $register->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  name?, warehouse_id?
     */
    public function updateRegister(PosRegister $register, array $data): PosRegister
    {
        $validated = Validator::make($data, [
            'warehouse_id' => ['sometimes', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'name' => ['sometimes', 'string', 'max:120'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($register, $validated): PosRegister {
            /** @var PosRegister $locked */
            $locked = PosRegister::query()->lockForUpdate()->findOrFail($register->id);

            if (isset($validated['warehouse_id']) && (int) $validated['warehouse_id'] !== $locked->warehouse_id) {
                if ($this->hasOpenSession($locked)) {
                    throw ApiException::conflict('session_open', 'Close the register\'s open session first.');
                }

                if ($locked->is_active) {
                    $this->assertWarehouseCeiling(Warehouse::query()->lockForUpdate()->findOrFail($validated['warehouse_id']), $locked->id);
                }
            }

            $locked->forceFill($validated)->save();

            return $locked;
        });
    }

    /**
     * Reactivation counts against the plan limit, so it has its own route
     * with usage.limit:max_pos_registers (§11.10).
     */
    public function activateRegister(PosRegister $register): PosRegister
    {
        return DB::connection('tenant')->transaction(function () use ($register): PosRegister {
            /** @var PosRegister $locked */
            $locked = PosRegister::query()->lockForUpdate()->findOrFail($register->id);

            if (! $locked->is_active) {
                $this->assertWarehouseCeiling(Warehouse::query()->lockForUpdate()->findOrFail($locked->warehouse_id), $locked->id);
                $locked->forceFill(['is_active' => true])->save();
            }

            return $locked;
        });
    }

    /**
     * Sets or clears the card terminal. Credentials are write-only: they are
     * validated for the provider's keys and never returned.
     *
     * @param  array<string, mixed>|null  $credentials
     */
    public function setTerminal(PosRegister $register, ?string $provider, ?array $credentials): PosRegister
    {
        if ($provider === null) {
            $register->forceFill(['terminal_provider' => null, 'terminal_credentials' => null])->save();

            return $register;
        }

        Validator::make(['provider' => $provider, 'credentials' => $credentials], [
            'provider' => ['required', Rule::in(PosRegister::TERMINAL_PROVIDERS)],
            'credentials' => ['required', 'array'],
            ...collect(PosTerminalGatewayFactory::credentialKeys($provider))
                ->mapWithKeys(static fn (string $key): array => ['credentials.'.$key => ['required', 'string', 'max:500']])->all(),
        ])->validate();

        $keys = PosTerminalGatewayFactory::credentialKeys($provider);
        $register->forceFill([
            'terminal_provider' => $provider,
            'terminal_credentials' => array_map('strval', array_intersect_key((array) $credentials, array_flip($keys))),
        ])->save();

        return $register;
    }

    /**
     * DELETE deactivates (§51.8): sales history keeps pointing at it.
     */
    public function deactivateRegister(PosRegister $register): PosRegister
    {
        return DB::connection('tenant')->transaction(function () use ($register): PosRegister {
            /** @var PosRegister $locked */
            $locked = PosRegister::query()->lockForUpdate()->findOrFail($register->id);

            if ($this->hasOpenSession($locked)) {
                throw ApiException::conflict('session_open', 'Close the register\'s open session first.');
            }

            $locked->forceFill(['is_active' => false])->save();

            return $locked;
        });
    }

    /**
     * @param  array{warehouse_id?: int, is_active?: bool}  $filters
     * @return Collection<int, PosRegister>
     */
    public function listRegisters(array $filters = []): Collection
    {
        return PosRegister::query()->with('warehouse:id,name')
            ->when(isset($filters['warehouse_id']), static fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')->get();
    }

    private function hasOpenSession(PosRegister $register): bool
    {
        return PosSession::query()->where('pos_register_id', $register->id)->where('status', PosSession::OPEN)->exists();
    }

    /**
     * platform_settings.max_pos_registers_per_warehouse (§13.2): active
     * registers on one warehouse.
     */
    private function assertWarehouseCeiling(Warehouse $warehouse, ?int $exceptRegisterId = null): void
    {
        $ceiling = $this->platformSettings->get('max_pos_registers_per_warehouse');

        if ($ceiling === null) {
            return;
        }

        $count = PosRegister::query()->where('warehouse_id', $warehouse->id)->where('is_active', true)
            ->when($exceptRegisterId !== null, static fn ($q) => $q->whereKeyNot($exceptRegisterId))->count();

        if ($count >= (int) $ceiling) {
            throw ApiException::unprocessable('warehouse_register_limit', 'This warehouse already has the most registers allowed.', ['limit' => (int) $ceiling]);
        }
    }
}
