<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrDepartment;
use App\Modules\Hr\Services\HrSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A department's own attendance rules (spec §58.3). Show answers with the
 * rules in force and whether they are the department's own.
 */
final class DepartmentSettingsController extends Controller
{
    public function __construct(
        private readonly HrSettingsService $settings,
        private readonly HrPresenter $presenter,
    ) {}

    public function show(HrDepartment $department): JsonResponse
    {
        $own = $this->settings->getDepartmentSettings($department);

        return APIResponse::success([...$this->presenter->settings($own ?? $this->settings->getDefaultSettings()), 'is_default' => $own === null]);
    }

    public function update(Request $request, HrDepartment $department): JsonResponse
    {
        return APIResponse::success([...$this->presenter->settings($this->settings->setDepartmentSettings($department, $request->all())), 'is_default' => false], 'Department settings saved');
    }

    public function destroy(HrDepartment $department): JsonResponse
    {
        $this->settings->deleteDepartmentSettings($department);

        return APIResponse::success([...$this->presenter->settings($this->settings->getDefaultSettings()), 'is_default' => true], 'The department now uses the default settings');
    }
}
