<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Affiliates\Http\Resources\AffiliateResource;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Services\AffiliateAuthService;
use App\Modules\Auth\Http\Requests\ChangePasswordRequest;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Http\Requests\VerifyEmailRequest;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Affiliate authentication routes (spec §10.5).
 */
final class AffiliateAuthController extends Controller
{
    public function __construct(private readonly AffiliateAuthService $auth) {}

    public function apply(Request $request): JsonResponse
    {
        $affiliate = $this->auth->apply($request->all(), $request);

        return APIResponse::created((new AffiliateResource($affiliate))->forPortal(), 'Application received; verify your email to continue');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            $request,
            (string) ($request->validated('device_name') ?? $request->userAgent() ?? 'api'),
        );

        return APIResponse::success([
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'affiliate' => (new AffiliateResource($result['affiliate']))->forPortal(),
        ], 'Logged in');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->affiliate($request));

        return APIResponse::success(null, 'Logged out');
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $affiliate = $this->auth->verifyEmail(
            (string) $request->validated('id'),
            (string) $request->validated('hash'),
            (int) $request->validated('expires'),
            (string) $request->validated('signature'),
        );

        return APIResponse::success((new AffiliateResource($affiliate))->forPortal(), 'Email verified');
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $this->auth->resendVerificationEmail($this->affiliate($request));

        return APIResponse::accepted(null, 'Verification email sent if the address is unverified');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->forgotPassword((string) $request->validated('email'));

        return APIResponse::accepted(null, 'If the account exists, a reset link has been sent');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword(
            (string) $request->validated('token'),
            (string) $request->validated('email'),
            (string) $request->validated('password'),
        );

        return APIResponse::success(null, 'Password reset');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword($this->affiliate($request), (string) $request->validated('current_password'), (string) $request->validated('password'));

        return APIResponse::success(null, 'Password changed');
    }

    private function affiliate(Request $request): Affiliate
    {
        /** @var Affiliate */
        return $request->user();
    }
}
