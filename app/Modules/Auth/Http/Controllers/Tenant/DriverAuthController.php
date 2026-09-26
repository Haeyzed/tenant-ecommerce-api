<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\Tenant\DriverAuthService;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\Driver;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver authentication routes (spec §10.5): OTP for the first login and
 * PIN resets, then phone and PIN.
 */
final class DriverAuthController extends Controller
{
    public function __construct(
        private readonly DriverAuthService $auth,
        private readonly ShippingPresenter $presenter,
    ) {}

    /**
     * Always 200 for a well-formed number, whether or not it is a driver's.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $phone = $request->validate(['phone' => ['required', 'string', 'max:32']])['phone'];
        $this->auth->requestOtp($phone);

        return APIResponse::success(null, 'If this number belongs to a driver, a code has been sent.');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'otp' => ['required', 'string'],
            'pin' => ['required', 'string', 'confirmed'],
        ]);

        $this->auth->verifyOtpAndSetPin($validated['phone'], $validated['otp'], $validated['pin']);

        return APIResponse::success(null, 'PIN set. Sign in with your phone number and PIN.');
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'pin' => ['required', 'string', 'max:6'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $result = $this->auth->login($validated['phone'], $validated['pin'], (string) ($validated['device_name'] ?? $request->userAgent() ?? 'api'));

        return APIResponse::success([
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'driver' => $this->presenter->driver($result['driver']),
        ], 'Logged in');
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();
        $this->auth->logout($driver);

        return APIResponse::success(null, 'Logged out');
    }
}
