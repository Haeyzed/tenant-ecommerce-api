<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Http\Requests\UpdatePreferencesRequest;
use App\Modules\Auth\Http\Requests\VerifyEmailRequest;
use App\Modules\Auth\Http\Resources\PlatformUserResource;
use App\Modules\Auth\Services\Landlord\AuthService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-user authentication routes (spec §10.5).
 */
final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (string) ($request->validated('device_name') ?? $request->userAgent() ?? 'api'),
        );

        return APIResponse::success([
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'user' => new PlatformUserResource($result['user']),
        ], 'Logged in');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->user($request));

        return APIResponse::success(null, 'Logged out');
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $user = $this->auth->verifyEmail(
            (string) $request->validated('id'),
            (string) $request->validated('hash'),
            (int) $request->validated('expires'),
            (string) $request->validated('signature'),
        );

        return APIResponse::success(new PlatformUserResource($user), 'Email verified');
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $this->auth->resendVerificationEmail($this->user($request));

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

    public function me(Request $request): JsonResponse
    {
        $profile = $this->auth->profile($this->user($request));
        $profile['user'] = new PlatformUserResource($profile['user']);

        return APIResponse::success($profile);
    }

    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $this->auth->updatePreferences($this->user($request), $request->validated());

        return APIResponse::success(new PlatformUserResource($user), 'Preferences updated');
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
