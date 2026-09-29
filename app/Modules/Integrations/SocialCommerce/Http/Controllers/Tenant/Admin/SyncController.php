<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Integrations\SocialCommerce\Http\SocialCommercePresenter;
use App\Modules\Integrations\SocialCommerce\Jobs\RunSocialCommerceSync;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceSyncLog;
use App\Modules\Integrations\SocialCommerce\Services\SocialCommerceSyncService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manual syncs per account, single listings and the log (spec §69.3).
 */
final class SyncController extends Controller
{
    public function __construct(
        private readonly SocialCommerceSyncService $sync,
        private readonly SocialCommercePresenter $presenter,
    ) {}

    public function products(SocialCommerceAccount $account): JsonResponse
    {
        return $this->queue($account, 'products');
    }

    public function orders(SocialCommerceAccount $account): JsonResponse
    {
        if (! $account->takesOrders()) {
            throw ApiException::unprocessable('orders_not_supported', 'Orders cannot be imported from this channel.');
        }

        return $this->queue($account, 'orders');
    }

    public function pushProduct(SocialCommerceAccount $account, Product $product): JsonResponse
    {
        $this->active($account);

        return APIResponse::success($this->presenter->listing($this->sync->pushProduct($account, $product)), 'Product sent to the channel');
    }

    public function removeProduct(SocialCommerceAccount $account, Product $product): JsonResponse
    {
        $this->sync->removeProduct($account, $product);

        return APIResponse::success(null, 'Product removed from the channel');
    }

    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'account_id' => ['sometimes', 'integer'],
            'sync_type' => ['sometimes', Rule::in(SocialCommerceSyncLog::TYPES)],
            'status' => ['sometimes', Rule::in(SocialCommerceSyncLog::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->sync->getSyncLogs($filters)->through(fn (SocialCommerceSyncLog $l): array => $this->presenter->log($l)));
    }

    public function metrics(): JsonResponse
    {
        return APIResponse::success($this->sync->getSyncMetrics());
    }

    private function queue(SocialCommerceAccount $account, string $type): JsonResponse
    {
        $this->active($account);
        /** @var Tenant $tenant */
        $tenant = tenant();
        RunSocialCommerceSync::dispatch((string) $tenant->getTenantKey(), $account->id, $type, RunSocialCommerceSync::MANUAL);

        return APIResponse::accepted(['account_id' => $account->id, 'sync_type' => $type], 'Sync started. Its result will appear in the sync log.');
    }

    private function active(SocialCommerceAccount $account): void
    {
        if (! $account->is_active) {
            throw ApiException::unprocessable('account_inactive', 'Switch this channel on first.');
        }
    }
}
