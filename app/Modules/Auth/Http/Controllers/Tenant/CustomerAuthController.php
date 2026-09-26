<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\ChangePasswordRequest;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Http\Requests\VerifyEmailRequest;
use App\Modules\Auth\Services\Tenant\CustomerAuthService;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer authentication routes (spec §10.5). register and login read
 * X-Guest-Token so the guest cart can be merged.
 */
final class CustomerAuthController extends Controller
{
    public function __construct(private readonly CustomerAuthService $auth) {}

    public function register(Request $request): JsonResponse
    {
        $result = $this->auth->register(
            $request->only(['name', 'email', 'phone', 'password', 'password_confirmation', 'custom_fields']),
            ResolveGuestToken::from($request),
            (string) ($request->input('device_name') ?? $request->userAgent() ?? 'api'),
        );

        return APIResponse::created($this->tokenPayload($result), 'Account created');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            ResolveGuestToken::from($request),
            (string) ($request->validated('device_name') ?? $request->userAgent() ?? 'api'),
        );

        return APIResponse::success($this->tokenPayload($result), 'Logged in');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->customer($request));

        return APIResponse::success(null, 'Logged out');
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $customer = $this->auth->verifyEmail(
            (string) $request->validated('id'),
            (string) $request->validated('hash'),
            (int) $request->validated('expires'),
            (string) $request->validated('signature'),
        );

        return APIResponse::success((new CustomerResource($customer))->forCustomer(), 'Email verified');
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $this->auth->resendVerificationEmail($this->customer($request));

        return APIResponse::accepted(null, 'Verification email sent if the address is unverified');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->forgotPassword((string) $request->validated('email'));

        return APIResponse::accepted(null, 'If the account exists, a reset link has been sent');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword((string) $request->validated('token'), (string) $request->validated('email'), (string) $request->validated('password'));

        return APIResponse::success(null, 'Password reset');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword($this->customer($request), (string) $request->validated('current_password'), (string) $request->validated('password'));

        return APIResponse::success(null, 'Password changed');
    }

    /**
     * @param  array{token: string, token_type: string, expires_at: string|null, customer: Customer}  $result
     * @return array<string, mixed>
     */
    private function tokenPayload(array $result): array
    {
        return [
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'customer' => (new CustomerResource($result['customer']))->forCustomer(),
        ];
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
