<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\WooCommerce\Http\WooCommercePresenter;
use App\Modules\Integrations\WooCommerce\Services\WooCommerceSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The WooCommerce connection (spec §68.4).
 */
final class SettingsController extends Controller
{
    private const array CREDENTIALS = ['store_url', 'consumer_key', 'consumer_secret'];

    private const array TOGGLES = ['is_active', 'sync_products', 'sync_categories', 'sync_orders', 'sync_tax_rates', 'order_sync_direction', 'import_warehouse_id', 'sync_interval_minutes'];

    public function __construct(
        private readonly WooCommerceSettingsService $settings,
        private readonly WooCommercePresenter $presenter,
    ) {}

    public function show(): JsonResponse
    {
        return APIResponse::success($this->presenter->settings($this->settings->getSettings()));
    }

    /**
     * Body: store_url?, consumer_key?, consumer_secret? (changing any pauses
     * sync), and the toggles: is_active?, sync_*?, order_sync_direction?,
     * import_warehouse_id?, sync_interval_minutes?
     */
    public function update(Request $request): JsonResponse
    {
        $this->settings->saveCredentials($request->only(self::CREDENTIALS));
        $settings = $this->settings->updateSyncToggles($request->only(self::TOGGLES));

        return APIResponse::success($this->presenter->settings($settings), 'WooCommerce settings saved');
    }

    public function testConnection(): JsonResponse
    {
        $ok = $this->settings->testConnection();

        return APIResponse::success(['connected' => $ok], $ok ? 'Connected to WooCommerce' : 'WooCommerce could not be reached with these details');
    }
}
