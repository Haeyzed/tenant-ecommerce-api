<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Compares every inventory balance with its movement ledger (spec §32.8,
 * §77.6) and reports drift. It never corrects anything: a drifted row is
 * investigated, then repaired with InventoryService::rebuildBalance().
 */
#[Signature('inventory:verify {tenant? : Only this tenant id}')]
#[Description('Report inventory rows whose balance differs from the movement ledger')]
final class VerifyInventory extends Command
{
    public function handle(InventoryService $inventory): int
    {
        $tenants = Tenant::query()
            ->whereNotNull('provisioned_at')
            ->whereNull('purged_at')
            ->when($this->argument('tenant'), static fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($tenants as $tenant) {
            $drift = $tenant->run(static fn () => $inventory->drift());

            if ($drift->isEmpty()) {
                continue;
            }

            $total += $drift->count();
            Log::warning('Inventory drift detected.', ['tenant_id' => $tenant->id, 'rows' => $drift->count()]);

            $this->warn("Tenant {$tenant->id}: {$drift->count()} drifted rows");
            $this->table(
                ['inventory_id', 'warehouse_id', 'product_id', 'variant_id', 'quantity', 'ledger', 'reserved', 'ledger reserved'],
                $drift->map(static fn (object $row): array => [
                    $row->id, $row->warehouse_id, $row->product_id, $row->product_variant_id,
                    $row->quantity, $row->ledger_quantity, $row->reserved_quantity, $row->ledger_reserved,
                ])->all(),
            );
        }

        $this->info("Checked {$tenants->count()} tenants; {$total} drifted rows.");

        return $total === 0 ? self::SUCCESS : self::FAILURE;
    }
}
