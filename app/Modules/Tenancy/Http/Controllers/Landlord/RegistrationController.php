<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Modules\Tenancy\Services\TenantRegistrationService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Self-service sign-up (spec §9.3, §9.8). Registrations are addressed by
 * public_id only.
 */
final class RegistrationController extends Controller
{
    public function __construct(private readonly TenantRegistrationService $registrations) {}

    public function store(Request $request): JsonResponse
    {
        $registration = $this->registrations->register($request->all(), $request);

        return APIResponse::accepted([
            'registration_id' => $registration->public_id,
            'status' => $registration->status,
            'expires_at' => $registration->verification_expires_at->toIso8601String(),
        ], 'Check your email for the verification code');
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registration_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $registration = TenantRegistration::query()->where('public_id', $validated['registration_id'])->firstOrFail();

        return APIResponse::success($this->registrations->verify($registration, $validated['code']), 'Email verified');
    }

    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate(['registration_id' => ['required', 'uuid']]);

        $this->registrations->resendVerification(
            TenantRegistration::query()->where('public_id', $validated['registration_id'])->firstOrFail(),
        );

        return APIResponse::accepted(null, 'A new code has been sent');
    }

    public function status(TenantRegistration $registration): JsonResponse
    {
        $tenant = $registration->tenant;

        return APIResponse::success([
            'registration_status' => $registration->status,
            'tenant_status' => $tenant?->status->value,
            'domain' => $tenant?->primaryDomain()?->domain,
        ]);
    }

    public function checkout(Request $request, TenantRegistration $registration): JsonResponse
    {
        $validated = $request->validate(['gateway' => ['required', Rule::in(['flutterwave', 'paystack', 'stripe'])]]);

        return APIResponse::created($this->registrations->createCheckout($registration, $validated['gateway']), 'Checkout started');
    }
}
