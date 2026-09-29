<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Services;

use App\Modules\Integrations\SocialCommerce\Drivers\MetaCatalogDriver;
use App\Modules\Integrations\SocialCommerce\Drivers\SocialChannelDriver;
use App\Modules\Integrations\SocialCommerce\Drivers\SocialCommerceException;
use App\Modules\Integrations\SocialCommerce\Drivers\TikTokShopDriver;
use App\Modules\Integrations\SocialCommerce\Jobs\RunSocialCommerceSync;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceProductMap;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Channel accounts (spec §69.3). The tenant supplies the access token
 * directly for now (UD-23); it is verified with the provider before the
 * account is saved, and never returned. Instagram never syncs orders, and
 * orders need a fulfilment warehouse. Each active account runs its own
 * self-scheduling sync chain.
 */
final readonly class SocialCommerceAccountService
{
    /** Provider ids: digits, letters, dots, dashes and underscores. */
    private const string REFERENCE = '/^[A-Za-z0-9_.-]{1,191}$/';

    public function driver(SocialCommerceAccount $account): SocialChannelDriver
    {
        return $account->channel === SocialCommerceAccount::TIKTOK_SHOP ? app(TikTokShopDriver::class) : app(MetaCatalogDriver::class);
    }

    /**
     * @param  array<string, mixed>  $credentials  access_token, account_reference, order_account_reference?, and the toggles
     */
    public function connectAccount(string $channel, array $credentials): SocialCommerceAccount
    {
        $validated = Validator::make([...$credentials, 'channel' => $channel], [
            'channel' => ['required', Rule::in(SocialCommerceAccount::CHANNELS)],
            'access_token' => ['required', 'string', 'max:4000'],
            'account_reference' => ['required', 'string', 'regex:'.self::REFERENCE,
                Rule::unique('tenant.social_commerce_accounts', 'account_reference')->where('channel', $channel)],
            'order_account_reference' => ['sometimes', 'nullable', 'string', 'regex:'.self::REFERENCE],
            ...$this->toggleRules(),
        ])->validate();

        $account = new SocialCommerceAccount;
        $account->forceFill([
            'is_active' => true,
            'sync_products' => true,
            'sync_orders' => in_array($channel, SocialCommerceAccount::ORDER_CHANNELS, true),
            'sync_interval_minutes' => 15,
            ...$validated,
        ]);
        $this->assertConsistent($account);

        try {
            $this->driver($account)->verify($account);
        } catch (SocialCommerceException $e) {
            throw ApiException::unprocessable('social_commerce_connection_failed', $e->getMessage());
        }

        $account->forceFill(['sync_chain_id' => (string) Str::uuid()])->save();
        $this->dispatchChain($account);

        return $account;
    }

    public function testConnection(SocialCommerceAccount $account): bool
    {
        try {
            $this->driver($account)->verify($account);

            return true;
        } catch (SocialCommerceException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data  access_token? (replaces it), order_account_reference?, is_active?, sync_products?, sync_orders?, fulfilment_warehouse_id?, sync_interval_minutes?
     */
    public function updateSyncToggles(SocialCommerceAccount $account, array $data): SocialCommerceAccount
    {
        $validated = Validator::make($data, [
            'access_token' => ['sometimes', 'string', 'max:4000'],
            'order_account_reference' => ['sometimes', 'nullable', 'string', 'regex:'.self::REFERENCE],
            ...$this->toggleRules(),
        ])->validate();

        $wasActive = $account->is_active;
        $account->forceFill($validated);
        $this->assertConsistent($account);

        if (isset($validated['access_token'])) {
            try {
                $this->driver($account)->verify($account);
            } catch (SocialCommerceException $e) {
                throw ApiException::unprocessable('social_commerce_connection_failed', $e->getMessage());
            }
        }

        $starting = $account->is_active && ! $wasActive;
        $account->forceFill(['sync_chain_id' => $account->is_active ? ($starting ? (string) Str::uuid() : $account->sync_chain_id) : null])->save();

        if ($starting) {
            $this->dispatchChain($account);
        }

        return $account;
    }

    /**
     * Deactivates the account and forgets its product maps (§69.3); the
     * listings on the channel are removed first, as far as it allows.
     */
    public function disconnectAccount(SocialCommerceAccount $account): SocialCommerceAccount
    {
        $ids = SocialCommerceProductMap::query()->where('social_commerce_account_id', $account->id)->whereNotNull('external_product_id')->pluck('external_product_id')->all();

        try {
            foreach (array_chunk($ids, 100) as $chunk) {
                $this->driver($account)->remove($account, $chunk);
            }
        } catch (SocialCommerceException) {
            // The token may already be revoked; the listings then stay until removed on the channel.
        }

        DB::connection('tenant')->transaction(function () use ($account): void {
            SocialCommerceProductMap::query()->where('social_commerce_account_id', $account->id)->delete();
            $account->forceFill(['is_active' => false, 'sync_chain_id' => null])->save();
        });

        return $account;
    }

    /**
     * @return Collection<int, SocialCommerceAccount>
     */
    public function listAccounts(): Collection
    {
        return SocialCommerceAccount::query()->orderBy('channel')->orderBy('id')->get();
    }

    /**
     * The daily watchdog: active accounts whose chain stalled restart.
     */
    public function restartStaleChains(): int
    {
        $restarted = 0;

        foreach (SocialCommerceAccount::query()->where('is_active', true)->get() as $account) {
            if ($account->last_synced_at !== null && $account->last_synced_at->gt(now()->subMinutes(2 * $account->sync_interval_minutes))) {
                continue;
            }

            $account->forceFill(['sync_chain_id' => (string) Str::uuid()])->save();
            $this->dispatchChain($account);
            $restarted++;
        }

        return $restarted;
    }

    private function assertConsistent(SocialCommerceAccount $account): void
    {
        if ($account->sync_orders && ! $account->takesOrders()) {
            throw ApiException::unprocessable('orders_not_supported', $account->channel === SocialCommerceAccount::INSTAGRAM
                ? 'Instagram has no checkout; its shoppers buy on your storefront.'
                : 'Orders from this channel cannot be imported yet.');
        }

        if ($account->sync_orders && $account->fulfilment_warehouse_id === null) {
            throw ApiException::unprocessable('fulfilment_warehouse_required', 'Choose the warehouse that fulfils this channel\'s orders.');
        }

        if ($account->sync_orders && $account->channel === SocialCommerceAccount::FACEBOOK_SHOP && $account->order_account_reference === null) {
            throw ApiException::unprocessable('order_account_required', 'Enter the commerce account id that receives Facebook Shop orders.');
        }

        if ($account->fulfilment_warehouse_id !== null && ! Warehouse::query()->whereKey($account->fulfilment_warehouse_id)->where('is_active', true)->exists()) {
            throw ApiException::unprocessable('warehouse_inactive', 'Choose an active warehouse.');
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function toggleRules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'sync_products' => ['sometimes', 'boolean'],
            'sync_orders' => ['sometimes', 'boolean'],
            'fulfilment_warehouse_id' => ['sometimes', 'nullable', 'integer'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
        ];
    }

    private function dispatchChain(SocialCommerceAccount $account): void
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant && $account->is_active) {
            RunSocialCommerceSync::dispatch((string) $tenant->getTenantKey(), $account->id, null, RunSocialCommerceSync::SCHEDULED, $account->sync_chain_id);
        }
    }
}
