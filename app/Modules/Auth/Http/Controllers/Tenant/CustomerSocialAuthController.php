<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\Tenant\CustomerSocialAuthService;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSocialAccount;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer social sign-in and account linking (D-132). The storefront
 * starts a flow (redirect), sends the shopper to authorization_url, and
 * posts the code and state it receives back (callback).
 */
final class CustomerSocialAuthController extends Controller
{
    public function __construct(private readonly CustomerSocialAuthService $social) {}

    /**
     * The sign-in methods this store offers.
     */
    public function providers(): JsonResponse
    {
        return APIResponse::success(['providers' => $this->social->providers()]);
    }

    /**
     * Body: custom_fields? (the store's registration fields, used only if a new account is created)
     */
    public function redirect(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate(['custom_fields' => ['sometimes', 'array']]);

        return APIResponse::success($this->social->start($provider, CustomerSocialAuthService::LOGIN, null, $validated['custom_fields'] ?? []));
    }

    /**
     * Body: code, state (as received from the provider), device_name?
     */
    public function callback(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'state' => ['required', 'string', 'max:512'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $result = $this->social->login($provider, $validated['state'], ResolveGuestToken::from($request), (string) ($validated['device_name'] ?? $request->userAgent() ?? 'api'));

        $payload = [
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'registered' => $result['registered'],
            'customer' => (new CustomerResource($result['customer']))->forCustomer(),
        ];

        return $result['registered'] ? APIResponse::created($payload, 'Account created') : APIResponse::success($payload, 'Logged in');
    }

    public function accounts(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return APIResponse::success([
            'has_password' => $customer->password !== null,
            'available' => $this->social->providers(),
            'linked' => $this->social->accounts($customer)->map(static fn (CustomerSocialAccount $a): array => [
                'provider' => $a->provider,
                'email' => $a->provider_email,
                'linked_at' => $a->created_at->toIso8601String(),
                'last_used_at' => $a->last_used_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function linkRedirect(Request $request, string $provider): JsonResponse
    {
        return APIResponse::success($this->social->start($provider, CustomerSocialAuthService::LINK, $this->customer($request)));
    }

    /**
     * Body: code, state
     */
    public function linkCallback(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:2048'], 'state' => ['required', 'string', 'max:512']]);
        $account = $this->social->link($this->customer($request), $provider, $validated['state']);

        return APIResponse::success(['provider' => $account->provider, 'email' => $account->provider_email], ucfirst($provider).' linked');
    }

    public function unlink(Request $request, string $provider): JsonResponse
    {
        $this->social->unlink($this->customer($request), $provider);

        return APIResponse::success(null, ucfirst($provider).' unlinked');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
