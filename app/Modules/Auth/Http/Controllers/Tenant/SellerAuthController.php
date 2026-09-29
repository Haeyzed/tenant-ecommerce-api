<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\ChangePasswordRequest;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Services\Tenant\SellerAuthService;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\Seller;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller authentication routes (spec §10.5). Registration answers with the
 * pending application, not a token: sellers log in once approved.
 */
final class SellerAuthController extends Controller
{
    public function __construct(
        private readonly SellerAuthService $auth,
        private readonly MarketplacePresenter $presenter,
    ) {}

    /**
     * Body: business_name, contact_name?, email, phone?, password, password_confirmation.
     */
    public function register(Request $request): JsonResponse
    {
        $seller = $this->auth->register($request->only(['business_name', 'contact_name', 'email', 'phone', 'password', 'password_confirmation']));

        return APIResponse::created($this->presenter->sellerForSelf($seller), 'Application received: you can sign in once it is approved');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login((string) $request->validated('email'), (string) $request->validated('password'),
            (string) ($request->validated('device_name') ?? $request->userAgent() ?? 'api'));

        return APIResponse::success([
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'seller' => $this->presenter->sellerForSelf($result['seller']),
        ], 'Logged in');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->seller($request));

        return APIResponse::success(null, 'Logged out');
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
        $this->auth->changePassword($this->seller($request), (string) $request->validated('current_password'), (string) $request->validated('password'));

        return APIResponse::success(null, 'Password changed');
    }

    private function seller(Request $request): Seller
    {
        /** @var Seller */
        return $request->user();
    }
}
