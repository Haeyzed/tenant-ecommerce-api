<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Http\DocumentPresenter;
use App\Modules\Documents\Models\BarcodeSetting;
use App\Modules\Documents\Services\BarcodeSettingsService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sticker-sheet layouts and the sheet generator (spec §43.4, §43.7).
 */
final class BarcodeSettingController extends Controller
{
    public function __construct(
        private readonly BarcodeSettingsService $settings,
        private readonly DocumentPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->settings->listSettings()->map(fn (BarcodeSetting $s): array => $this->presenter->barcodeSetting($s))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $setting = $this->settings->createSetting($request->validate($this->settings->rules(true)));

        return APIResponse::created($this->presenter->barcodeSetting($setting->refresh()), 'Layout created');
    }

    public function update(Request $request, BarcodeSetting $setting): JsonResponse
    {
        return APIResponse::success($this->presenter->barcodeSetting($this->settings->updateSetting($setting, $request->validate($this->settings->rules(false)))), 'Layout updated');
    }

    public function destroy(BarcodeSetting $setting): JsonResponse
    {
        $this->settings->deleteSetting($setting);

        return APIResponse::success(null, 'Layout deleted');
    }

    public function setDefault(BarcodeSetting $setting): JsonResponse
    {
        return APIResponse::success($this->presenter->barcodeSetting($this->settings->setDefaultSetting($setting)), 'Default layout set');
    }

    public function generate(Request $request, WarehouseService $warehouses): Response
    {
        $validated = $request->validate($this->settings->labelRules());

        if (isset($validated['warehouse_id'])) {
            /** @var User $actor */
            $actor = $request->user();
            $warehouses->assertVisible(Warehouse::query()->findOrFail((int) $validated['warehouse_id']), $actor);
        }

        $setting = isset($validated['barcode_setting_id']) ? BarcodeSetting::query()->findOrFail((int) $validated['barcode_setting_id']) : null;

        return $this->settings->generateStickerSheet(array_values($validated['items']), $setting, Arr::except($validated, ['items', 'barcode_setting_id']))->toResponse();
    }
}
