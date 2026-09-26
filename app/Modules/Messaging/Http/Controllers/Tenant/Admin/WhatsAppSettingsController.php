<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\WhatsAppSetting;
use App\Modules\Messaging\Services\WhatsAppSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant's own WhatsApp number (spec §16.4). Requires feature:whatsapp.
 */
final class WhatsAppSettingsController extends Controller
{
    public function __construct(private readonly WhatsAppSettingsService $whatsapp) {}

    public function show(): JsonResponse
    {
        return APIResponse::success($this->present($this->whatsapp->getSettings()));
    }

    public function update(Request $request): JsonResponse
    {
        return APIResponse::success($this->present($this->whatsapp->saveCredentials($request->only(['phone_number_id', 'business_account_id', 'access_token']))), 'WhatsApp settings saved');
    }

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate(['to' => ['required', 'string', 'regex:/^\+?[1-9]\d{6,14}$/']]);
        $sent = $this->whatsapp->sendTestMessage($validated['to']);

        return APIResponse::success(['sent' => $sent] + $this->present($this->whatsapp->getSettings()), $sent ? 'Test message sent' : 'The test message could not be sent');
    }

    public function activate(): JsonResponse
    {
        return APIResponse::success($this->present($this->whatsapp->activate()), 'WhatsApp number activated');
    }

    public function deactivate(): JsonResponse
    {
        return APIResponse::success($this->present($this->whatsapp->deactivate()), 'WhatsApp number deactivated');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WhatsAppSetting $settings): array
    {
        return [
            'phone_number_id' => $settings->phone_number_id,
            'business_account_id' => $settings->business_account_id,
            'has_access_token' => filled($settings->access_token),
            'is_active' => $settings->is_active,
            'verified_at' => $settings->verified_at?->toIso8601String(),
            'uses_platform_number' => ! $settings->is_active,
        ];
    }
}
