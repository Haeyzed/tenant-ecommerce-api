<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\SocialCommerce\Http\SocialCommercePresenter;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use App\Modules\Integrations\SocialCommerce\Services\SocialCommerceAccountService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Channel accounts (spec §69.3).
 */
final class AccountController extends Controller
{
    private const array TOGGLES = ['order_account_reference', 'is_active', 'sync_products', 'sync_orders', 'fulfilment_warehouse_id', 'sync_interval_minutes'];

    public function __construct(
        private readonly SocialCommerceAccountService $accounts,
        private readonly SocialCommercePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->accounts->listAccounts()->map(fn (SocialCommerceAccount $a): array => $this->presenter->account($a))->values()->all());
    }

    /**
     * Body: channel, access_token, account_reference, order_account_reference?, sync_products?, sync_orders?, fulfilment_warehouse_id?, sync_interval_minutes?
     */
    public function store(Request $request): JsonResponse
    {
        $channel = (string) $request->input('channel', '');
        $account = $this->accounts->connectAccount($channel, $request->only(['access_token', 'account_reference', ...self::TOGGLES]));

        return APIResponse::created($this->presenter->account($account), 'Channel connected');
    }

    /**
     * Body: access_token? (replaces it), and the toggles.
     */
    public function update(Request $request, SocialCommerceAccount $account): JsonResponse
    {
        return APIResponse::success($this->presenter->account($this->accounts->updateSyncToggles($account, $request->only(['access_token', ...self::TOGGLES]))), 'Channel updated');
    }

    public function destroy(SocialCommerceAccount $account): JsonResponse
    {
        return APIResponse::success($this->presenter->account($this->accounts->disconnectAccount($account)), 'Channel disconnected');
    }

    public function testConnection(SocialCommerceAccount $account): JsonResponse
    {
        $ok = $this->accounts->testConnection($account);

        return APIResponse::success(['connected' => $ok], $ok ? 'Connected' : 'The channel refused this token or account id');
    }
}
