<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Services\PlatformPaymentGatewayService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform billing gateways (spec §15.10). Super-admin only by the derived
 * permissions.
 */
final class PaymentGatewayController extends Controller
{
    public function __construct(
        private readonly PlatformPaymentGatewayService $gateways,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success([
            'billing_payment_mode' => (string) $this->settings->get('billing_payment_mode'),
            'gateways' => $this->gateways->list(),
        ]);
    }

    public function update(Request $request, string $provider, string $mode): JsonResponse
    {
        $this->gateways->save($provider, $mode, $request->all(), $this->user($request));

        return APIResponse::success($this->row($provider, $mode), 'Gateway saved');
    }

    public function test(string $provider, string $mode): JsonResponse
    {
        return APIResponse::success($this->gateways->test($provider, $mode));
    }

    public function enable(Request $request, string $provider, string $mode): JsonResponse
    {
        $this->gateways->enable($provider, $mode, $this->user($request));

        return APIResponse::success($this->row($provider, $mode), 'Gateway enabled');
    }

    public function disable(Request $request, string $provider, string $mode): JsonResponse
    {
        $this->gateways->disable($provider, $mode, $this->user($request));

        return APIResponse::success($this->row($provider, $mode), 'Gateway disabled');
    }

    public function setDefault(Request $request, string $provider, string $mode): JsonResponse
    {
        $this->gateways->setDefault($provider, $mode, $this->user($request));

        return APIResponse::success($this->row($provider, $mode), 'Default gateway set');
    }

    /**
     * Safeguard 5 of spec §15.9: explicit confirm and a reason.
     */
    public function mode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:test,live'],
            'confirm' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->gateways->setBillingMode($validated['mode'], $validated['reason'], $this->user($request));

        return APIResponse::success(['billing_payment_mode' => (string) $this->settings->get('billing_payment_mode')], 'Billing mode updated');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $provider, string $mode): ?array
    {
        return collect($this->gateways->list())->first(static fn (array $row): bool => $row['provider'] === $provider && $row['mode'] === $mode);
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
