<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\ReceiptPrinter;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * Warehouse receipt printers (spec §43.6). The server never connects to a
 * printer; the POS client delivers the ESC/POS payload (Assumption A-28).
 */
final class ReceiptPrinterService
{
    /**
     * @param  list<int>|null  $warehouseIds  the staff member's visible warehouses; null = all
     * @return Collection<int, ReceiptPrinter>
     */
    public function listPrinters(?array $warehouseIds, ?int $warehouseId = null): Collection
    {
        return ReceiptPrinter::query()->with('warehouse:id,name')
            ->when($warehouseIds !== null, static fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->when($warehouseId !== null, static fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('warehouse_id')->orderBy('id')->get();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(bool $creating, ?ReceiptPrinter $printer = null): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $network = static fn (): bool => (request()->input('connection_type', $printer?->connection_type)) === 'network';

        return [
            'name' => [$required, 'string', 'max:120'],
            'warehouse_id' => [$required, 'integer', Rule::exists('tenant.warehouses', 'id')],
            'connection_type' => [$required, Rule::in(ReceiptPrinter::CONNECTIONS)],
            'capability_profile' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'characters_per_line' => ['sometimes', 'integer', 'min:16', 'max:96'],
            'ip_address' => [Rule::requiredIf($network), 'nullable', 'ip'],
            'port' => [Rule::requiredIf($network), 'nullable', 'integer', 'between:1,65535'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function createPrinter(array $data): ReceiptPrinter
    {
        $printer = new ReceiptPrinter($this->normalise($data));
        $printer->save();

        return $printer->refresh();
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function updatePrinter(ReceiptPrinter $printer, array $data): ReceiptPrinter
    {
        $printer->fill($data);
        $printer->fill($this->normalise($printer->getAttributes()))->save();

        return $printer;
    }

    public function deletePrinter(ReceiptPrinter $printer): void
    {
        $printer->delete();
    }

    public function activatePrinter(ReceiptPrinter $printer): void
    {
        $printer->forceFill(['is_active' => true])->save();
    }

    public function deactivatePrinter(ReceiptPrinter $printer): void
    {
        $printer->forceFill(['is_active' => false])->save();
    }

    /**
     * The active printer with the lowest id; null means browser printing.
     */
    public function getPrinterForWarehouse(Warehouse $warehouse): ?ReceiptPrinter
    {
        return ReceiptPrinter::query()->where('warehouse_id', $warehouse->id)->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Network details only apply to network printers.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        if (($data['connection_type'] ?? null) !== 'network') {
            $data['ip_address'] = null;
            $data['port'] = null;
        }

        return array_intersect_key($data, array_flip((new ReceiptPrinter)->getFillable()));
    }
}
