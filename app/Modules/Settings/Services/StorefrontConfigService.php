<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Auth\Support\DisplayPreferences;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * GET /api/storefront/config (spec §13.5): one cached, public response.
 * Cached in the tenant's own cache prefix; busted on settings writes and
 * expiring within five minutes so module state changes reach it quickly.
 */
final readonly class StorefrontConfigService
{
    private const string CACHE_KEY = 'storefront:config';

    private const int TTL = 300;

    /**
     * Modules whose state the storefront needs to show or hide features.
     */
    private const array STOREFRONT_MODULES = [
        'reward_points', 'gift_cards', 'multi_currency', 'back_in_stock_alerts', 'installments', 'booking', 'content_marketing',
    ];

    public function __construct(
        private StorefrontSettingsService $storefront,
        private TenantSettingsService $settings,
        private FeatureAccessService $features,
        private DisplayPreferences $display,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, fn (): array => $this->build());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        /** @var Tenant $tenant */
        $tenant = tenant();
        $settings = $this->settings;
        $display = $this->display->forStaff(null);

        $modules = [];

        foreach (self::STOREFRONT_MODULES as $key) {
            $modules[$key] = $this->features->tenantCanAccess($tenant, $key);
        }

        return [
            'storefront' => $this->storefront->all(),
            'business' => [
                'store_name' => $settings->get('store_name'),
                'logo_url' => $this->mediaUrl($settings->get('store_logo_media_id')),
                'favicon_url' => $this->mediaUrl($settings->get('favicon_media_id')),
                'store_contact_email' => $settings->get('store_contact_email'),
                'store_contact_phone' => $settings->get('store_contact_phone'),
                'store_address' => $settings->get('store_address'),
                'business_hours' => $settings->get('business_hours'),
            ],
            'formatting' => [
                'default_currency' => $settings->get('default_currency'),
                'default_currency_position' => $settings->get('default_currency_position'),
                'decimal_digits' => $settings->get('decimal_digits'),
                'locale' => $settings->get('locale'),
                'rtl_enabled' => $settings->get('rtl_enabled'),
                'timezone' => $display['timezone'],
                'date_format' => $display['date_format'],
                'time_format' => $display['time_format'],
            ],
            'checkout' => [
                'guest_checkout_enabled' => $settings->get('guest_checkout_enabled'),
                'prices_include_tax' => $settings->get('prices_include_tax'),
                'payment_mode' => $settings->get('payment_mode'),
            ],
            'modules' => $modules,
        ];
    }

    private function mediaUrl(mixed $mediaId): ?string
    {
        if (! is_numeric($mediaId)) {
            return null;
        }

        return Media::query()->find((int) $mediaId)?->getUrl();
    }
}
