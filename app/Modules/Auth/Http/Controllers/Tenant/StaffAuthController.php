<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\ChangePasswordRequest;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Http\Requests\UpdatePreferencesRequest;
use App\Modules\Auth\Http\Resources\StaffUserResource;
use App\Modules\Auth\Services\Tenant\AuthService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff authentication routes (spec §10.5).
 */
final class StaffAuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (string) ($request->validated('device_name') ?? $request->userAgent() ?? 'api'),
        );

        return $this->tokenResponse($result, 'Logged in');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->user($request));

        return APIResponse::success(null, 'Logged out');
    }

    public function refresh(Request $request): JsonResponse
    {
        return $this->tokenResponse($this->auth->refreshToken($this->user($request)), 'Token refreshed');
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
        $this->auth->changePassword(
            $this->user($request),
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
        );

        return APIResponse::success(null, 'Password changed');
    }

    public function me(Request $request): JsonResponse
    {
        $profile = $this->auth->profile($this->user($request));
        $profile['user'] = new StaffUserResource($profile['user']);

        return APIResponse::success($profile);
    }

    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $this->auth->updatePreferences($this->user($request), $request->validated());

        return APIResponse::success(new StaffUserResource($user), 'Preferences updated');
    }

    /**
     * @param  array{token: string, token_type: string, expires_at: string|null, user: User}  $result
     */
    private function tokenResponse(array $result, string $message): JsonResponse
    {
        return APIResponse::success([
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'user' => new StaffUserResource($result['user']),
        ], $message);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
