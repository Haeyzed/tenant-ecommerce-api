<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\BarcodeSetting;
use App\Modules\Documents\Services\BarcodeSettingsService;
use App\Modules\Documents\Services\PrintBarcodeService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The print-barcode screen (spec §43.5, §43.7).
 */
final class PrintBarcodeController extends Controller
{
    public function __construct(
        private readonly PrintBarcodeService $labels,
        private readonly WarehouseService $warehouses,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'warehouse_id' => ['sometimes', 'integer', Rule::exists('tenant.warehouses', 'id')],
        ]);

        return APIResponse::success($this->labels->searchProducts((string) $validated['q'], $this->warehouse($request, $validated['warehouse_id'] ?? null)));
    }

    public function generate(Request $request, BarcodeSettingsService $settings): Response
    {
        $validated = $request->validate($settings->labelRules());
        $this->warehouse($request, $validated['warehouse_id'] ?? null);
        $setting = isset($validated['barcode_setting_id']) ? BarcodeSetting::query()->findOrFail((int) $validated['barcode_setting_id']) : null;

        return $this->labels->generateLabels(array_values($validated['items']), $setting, Arr::except($validated, ['items', 'barcode_setting_id']))->toResponse();
    }

    private function warehouse(Request $request, mixed $id): ?Warehouse
    {
        if ($id === null) {
            return null;
        }

        /** @var User $actor */
        $actor = $request->user();
        $warehouse = Warehouse::query()->findOrFail((int) $id);
        $this->warehouses->assertVisible($warehouse, $actor);

        return $warehouse;
    }
}
