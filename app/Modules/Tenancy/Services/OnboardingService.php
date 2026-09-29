<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Tax\Models\TaxRate;
use App\Modules\Tenancy\Enums\DomainStatus;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;

/**
 * The owner's setup progress (spec §9.6), computed from existing data;
 * there is no checklist table. custom_domain is optional and does not
 * count towards completion.
 */
final readonly class OnboardingService
{
    /** The policy pages checkout and registration link to (§24.3). */
    public const array POLICY_PAGES = ['privacy_policy', 'terms', 'refund_policy'];

    public function __construct(private TenantSettingsService $settings) {}

    /**
     * @return array{steps: list<array{key: string, complete: bool, optional: bool}>, completed: int, total: int, dismissed: bool}
     */
    public function checklist(Tenant $tenant): array
    {
        $settings = $this->settings;
        $publishedPolicies = CmsPage::query()->whereIn('system_key', self::POLICY_PAGES)->where('status', CmsPage::PUBLISHED)->pluck('system_key')->all();

        $steps = [
            'store_details' => filled($settings->get('store_logo_media_id')) && filled($settings->get('store_contact_phone')),
            'payment_gateway' => $settings->get('payment_mode') === 'live'
                && TenantPaymentSetting::query()->where('mode', 'live')->where('is_active', true)->exists(),
            // Digital and service products ship nowhere.
            'shipping' => ShippingMethod::query()->where('is_active', true)->exists()
                || Product::query()->where('is_active', true)->whereNotIn('product_type', [Product::DIGITAL, Product::SERVICE])->doesntExist(),
            'tax' => (bool) $settings->get('tax_setup_confirmed', false) || TaxRate::query()->where('is_active', true)->exists(),
            'first_product' => Product::query()->where('is_active', true)->exists(),
            'policies' => array_diff(self::POLICY_PAGES, $publishedPolicies) === [],
            'custom_domain' => Domain::query()->where('tenant_id', $tenant->getTenantKey())->where('type', 'custom')
                ->whereIn('status', [DomainStatus::Verified->value, DomainStatus::Active->value])->exists(),
        ];

        $rows = [];

        foreach ($steps as $key => $complete) {
            $rows[] = ['key' => $key, 'complete' => $complete, 'optional' => $key === 'custom_domain'];
        }

        $required = array_filter($rows, static fn (array $r): bool => ! $r['optional']);

        return [
            'steps' => $rows,
            'completed' => count(array_filter($required, static fn (array $r): bool => $r['complete'])),
            'total' => count($required),
            'dismissed' => (bool) $settings->get('onboarding_dismissed', false),
        ];
    }
}
