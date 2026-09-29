<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Integrations\WooCommerce\Http\WooCommercePresenter;
use App\Modules\Integrations\WooCommerce\Jobs\RunWooCommerceSync;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceSyncLog;
use App\Modules\Integrations\WooCommerce\Services\WooCommerceSettingsService;
use App\Modules\Integrations\WooCommerce\Services\WooCommerceSyncService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manual syncs, pushes and the log (spec §68.4). A manual sync is queued
 * (it never overlaps a scheduled run); its result appears in the log.
 */
final class SyncController extends Controller
{
    public function __construct(
        private readonly WooCommerceSettingsService $settings,
        private readonly WooCommerceSyncService $sync,
        private readonly WooCommercePresenter $presenter,
    ) {}

    public function categories(): JsonResponse
    {
        return $this->queue('categories');
    }

    public function products(): JsonResponse
    {
        return $this->queue('products');
    }

    public function taxRates(): JsonResponse
    {
        return $this->queue('tax_rates');
    }

    public function orders(): JsonResponse
    {
        return $this->queue('orders');
    }

    public function pushProduct(Product $product): JsonResponse
    {
        $this->connected();
        $this->sync->pushProduct($product);

        return APIResponse::success(null, 'Product pushed to WooCommerce');
    }

    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'sync_type' => ['sometimes', Rule::in(WooCommerceSyncLog::TYPES)],
            'status' => ['sometimes', Rule::in(WooCommerceSyncLog::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->sync->getSyncLogs($filters)->through(fn (WooCommerceSyncLog $l): array => $this->presenter->log($l)));
    }

    public function metrics(): JsonResponse
    {
        return APIResponse::success($this->sync->getSyncMetrics());
    }

    private function queue(string $type): JsonResponse
    {
        $this->connected();
        /** @var Tenant $tenant */
        $tenant = tenant();
        RunWooCommerceSync::dispatch((string) $tenant->getTenantKey(), $type, RunWooCommerceSync::MANUAL);

        return APIResponse::accepted(['sync_type' => $type], 'Sync started. Its result will appear in the sync log.');
    }

    private function connected(): void
    {
        $settings = $this->settings->getSettings();

        if (! $settings->hasCredentials()) {
            throw ApiException::unprocessable('woocommerce_not_connected', 'Enter the store address, consumer key and secret first.');
        }

        if ($settings->import_warehouse_id === null) {
            throw ApiException::unprocessable('import_warehouse_required', 'Choose the import warehouse first.');
        }
    }
}
