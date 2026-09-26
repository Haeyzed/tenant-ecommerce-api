<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\TenantPaymentSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant's storefront gateways and payment mode (spec §15.4).
 */
final class PaymentSettingsController extends Controller
{
    public function __construct(private readonly TenantPaymentSettingsService $settings) {}

    public function index(): JsonResponse
    {
        return APIResponse::success([
            'payment_mode' => $this->settings->currentMode(),
            'gateways' => $this->settings->listGateways(),
        ]);
    }

    public function mode(Request $request): JsonResponse
    {
        $validated = $request->validate(['mode' => ['required', 'in:test,live']]);

        /** @var User $user */
        $user = $request->user();
        $this->settings->setPaymentMode($validated['mode'], $user);

        return APIResponse::success(['payment_mode' => $this->settings->currentMode()], 'Payment mode updated');
    }

    public function update(Request $request, string $provider, string $mode): JsonResponse
    {
        $this->settings->saveGatewayCredentials($provider, $mode, $request->only(['public_key', 'secret_key', 'webhook_secret']));

        return APIResponse::success($this->row($provider, $mode), 'Credentials saved');
    }

    public function test(string $provider, string $mode): JsonResponse
    {
        return APIResponse::success($this->settings->testGateway($provider, $mode));
    }

    public function activate(string $provider, string $mode): JsonResponse
    {
        $this->settings->activateGateway($provider, $mode);

        return APIResponse::success($this->row($provider, $mode), 'Gateway activated');
    }

    public function deactivate(string $provider, string $mode): JsonResponse
    {
        $this->settings->deactivateGateway($provider, $mode);

        return APIResponse::success($this->row($provider, $mode), 'Gateway deactivated');
    }

    public function onlineAvailability(Request $request, string $provider, string $mode): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->settings->setOnlineAvailability($provider, $mode, (bool) $validated['enabled']);

        return APIResponse::success($this->row($provider, $mode), 'Online availability updated');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $provider, string $mode): ?array
    {
        return collect($this->settings->listGateways())->first(static fn (array $r): bool => $r['provider'] === $provider && $r['mode'] === $mode);
    }
}
