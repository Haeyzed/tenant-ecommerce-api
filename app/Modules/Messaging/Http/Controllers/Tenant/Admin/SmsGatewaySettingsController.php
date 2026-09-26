<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\SmsGatewaySetting;
use App\Modules\Messaging\Services\SmsGatewaySettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant's SMS gateways (spec §16.3). Credentials are always masked.
 */
final class SmsGatewaySettingsController extends Controller
{
    public function __construct(private readonly SmsGatewaySettingsService $gateways) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->list());
    }

    /**
     * Creates or updates by provider.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'credentials' => ['required', 'array'],
        ]);

        $this->gateways->saveGatewayCredentials($validated['provider'], $validated['credentials']);

        return APIResponse::success($this->list(), 'Gateway saved');
    }

    public function setDefault(string $gateway): JsonResponse
    {
        $this->gateways->setDefaultGateway($gateway);

        return APIResponse::success($this->list(), 'Default gateway set');
    }

    public function activate(string $gateway): JsonResponse
    {
        $this->gateways->activateGateway($gateway);

        return APIResponse::success($this->list(), 'Gateway activated');
    }

    public function deactivate(string $gateway): JsonResponse
    {
        $this->gateways->deactivateGateway($gateway);

        return APIResponse::success($this->list(), 'Gateway deactivated');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(): array
    {
        return $this->gateways->listGateways()->map(static fn (SmsGatewaySetting $g): array => [
            'provider' => $g->provider,
            'credentials' => $g->maskedCredentials(),
            'is_active' => $g->is_active,
            'is_default' => $g->is_default,
            'updated_at' => $g->updated_at?->toIso8601String(),
        ])->values()->all();
    }
}
