<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant business settings (spec §13.4). commission_rate and app_version
 * are read-only; the custom mail password is never returned.
 */
final class TenantSettingsController extends Controller
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    public function show(): JsonResponse
    {
        return APIResponse::success($this->present());
    }

    public function update(Request $request): JsonResponse
    {
        $this->settings->update((array) $request->input('values', $request->except(['commission_rate', 'app_version'])));

        return APIResponse::success($this->present(), 'Settings updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(): array
    {
        $values = $this->settings->all();

        if (is_array($values['custom_mail_settings'] ?? null)) {
            $mail = $values['custom_mail_settings'];
            $mail['has_password'] = filled($mail['password'] ?? null);
            unset($mail['password']);
            $values['custom_mail_settings'] = $mail;
        }

        return $values;
    }
}
