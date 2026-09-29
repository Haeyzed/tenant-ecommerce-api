<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\Support\PublicUrlGuard;
use App\Modules\Integrations\WooCommerce\Jobs\RunWooCommerceSync;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceSettings;
use App\Modules\Integrations\WooCommerce\Support\WooCommerceClient;
use App\Modules\Integrations\WooCommerce\Support\WooCommerceException;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The WooCommerce connection (spec §68.1, §68.4). Credentials can be
 * replaced but never read back; changing them pauses sync until it is
 * switched on again, which re-tests the connection. Switching sync on
 * starts a self-scheduling run chain; each chain has an id, so a restart
 * retires the old one.
 */
final readonly class WooCommerceSettingsService
{
    public function getSettings(): WooCommerceSettings
    {
        $settings = WooCommerceSettings::query()->first();

        if ($settings === null) {
            $settings = new WooCommerceSettings;
            $settings->forceFill(['is_active' => false])->save();
            $settings->refresh();
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $data  store_url?, consumer_key?, consumer_secret?
     */
    public function saveCredentials(array $data): WooCommerceSettings
    {
        $validated = Validator::make($data, [
            'store_url' => ['sometimes', 'string', 'max:255'],
            'consumer_key' => ['sometimes', 'string', 'regex:/^ck_[A-Za-z0-9]{20,64}$/'],
            'consumer_secret' => ['sometimes', 'string', 'regex:/^cs_[A-Za-z0-9]{20,64}$/'],
        ], ['consumer_key.regex' => 'A WooCommerce consumer key starts with ck_.', 'consumer_secret.regex' => 'A WooCommerce consumer secret starts with cs_.'])->validate();

        if ($validated === []) {
            return $this->getSettings();
        }

        if (isset($validated['store_url'])) {
            $validated['store_url'] = PublicUrlGuard::assertPublic((string) $validated['store_url']);
        }

        $settings = $this->getSettings();
        $settings->forceFill([...$validated, 'is_active' => false, 'sync_chain_id' => null])->save();

        return $settings;
    }

    public function testConnection(): bool
    {
        try {
            (new WooCommerceClient($this->getSettings()))->get('products', ['per_page' => 1]);

            return true;
        } catch (WooCommerceException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data  is_active?, sync_products?, sync_categories?, sync_orders?, sync_tax_rates?, order_sync_direction?, import_warehouse_id?, sync_interval_minutes?
     */
    public function updateSyncToggles(array $data): WooCommerceSettings
    {
        $validated = Validator::make($data, [
            'is_active' => ['sometimes', 'boolean'],
            'sync_products' => ['sometimes', 'boolean'],
            'sync_categories' => ['sometimes', 'boolean'],
            'sync_orders' => ['sometimes', 'boolean'],
            'sync_tax_rates' => ['sometimes', 'boolean'],
            'order_sync_direction' => ['sometimes', Rule::in(WooCommerceSettings::DIRECTIONS)],
            'import_warehouse_id' => ['sometimes', 'nullable', 'integer'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
        ])->validate();

        if (isset($validated['import_warehouse_id']) && ! Warehouse::query()->whereKey($validated['import_warehouse_id'])->where('is_active', true)->exists()) {
            throw ApiException::unprocessable('warehouse_inactive', 'Choose an active warehouse.');
        }

        $settings = $this->getSettings();
        $wasActive = $settings->is_active;
        $settings->forceFill($validated);

        if ($settings->is_active) {
            if (! $settings->hasCredentials()) {
                throw ApiException::unprocessable('woocommerce_not_connected', 'Enter the store address, consumer key and secret first.');
            }

            // The fulfilling warehouse is required before product or order sync (§68.1).
            if (($settings->sync_products || $settings->sync_orders) && $settings->import_warehouse_id === null) {
                throw ApiException::unprocessable('import_warehouse_required', 'Choose the warehouse that receives imported stock and fulfils imported orders.');
            }
        }

        $starting = $settings->is_active && ! $wasActive;

        if ($starting) {
            // The credentials are checked against the store before sync starts (§68.4).
            try {
                (new WooCommerceClient($settings))->get('products', ['per_page' => 1]);
            } catch (WooCommerceException $e) {
                throw ApiException::unprocessable('woocommerce_connection_failed', $e->getMessage());
            }

            $settings->forceFill(['activated_at' => $settings->activated_at ?? now(), 'sync_chain_id' => (string) Str::uuid()]);
        }

        if (! $settings->is_active) {
            $settings->forceFill(['sync_chain_id' => null]);
        }

        $settings->save();

        if ($starting) {
            $this->dispatchChain($settings);
        }

        return $settings;
    }

    /**
     * The daily watchdog (§68.3): a chain that has not run for twice its
     * interval is restarted under a new id.
     */
    public function restartStaleChain(): bool
    {
        $settings = WooCommerceSettings::query()->first();

        if ($settings === null || ! $settings->is_active) {
            return false;
        }

        $threshold = now()->subMinutes(2 * $settings->sync_interval_minutes);

        if ($settings->last_synced_at !== null && $settings->last_synced_at->gt($threshold)) {
            return false;
        }

        $settings->forceFill(['sync_chain_id' => (string) Str::uuid()])->save();
        $this->dispatchChain($settings);

        return true;
    }

    private function dispatchChain(WooCommerceSettings $settings): void
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant) {
            RunWooCommerceSync::dispatch((string) $tenant->getTenantKey(), null, RunWooCommerceSync::SCHEDULED, $settings->sync_chain_id);
        }
    }
}
