<?php

declare(strict_types=1);

namespace App\Modules\Pos;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\WarehouseUsage;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Services\PosRegisterService;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires POS into the shared registries: the max_pos_registers counter
 * (§11.8) and the registers that keep a warehouse from being deleted
 * (§32.9).
 */
final class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(UsageCounterRegistry::class, static function (UsageCounterRegistry $registry): void {
            $registry->register('max_pos_registers', static fn (): int => PosRegisterService::countActive());
        });

        $this->app->afterResolving(WarehouseUsage::class, static function (WarehouseUsage $usage): void {
            $usage->register('pos_registers', static fn (Warehouse $w): bool => PosRegister::query()->where('warehouse_id', $w->id)->exists());
        });
    }
}
