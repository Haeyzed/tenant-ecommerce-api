<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Services\PlanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The initial commercial plans of spec §11.14. Keyed by slug: a plan and
 * its features and limits are created only when the slug does not exist,
 * and a price only where the plan has none for that currency and interval,
 * so edits made in production are never reverted.
 */
final class PlanCatalogueSeeder extends Seeder
{
    private const array BASIC_FEATURES = ['expenses', 'content_marketing'];

    private const array STANDARD_FEATURES = [
        'pos', 'purchasing', 'accounting', 'multi_currency', 'gift_cards', 'reward_points', 'sales_quotations',
        'back_in_stock_alerts', 'advanced_reporting', 'support', 'whatsapp', 'custom_email',
    ];

    private const array PREMIUM_FEATURES = [
        'hr', 'hr_payroll', 'hr_recruitment', 'marketplace', 'installments', 'product_subscriptions', 'sales_agents',
        'approval_workflows', 'project_management', 'ai_assistant', 'woocommerce', 'social_commerce', 'manufacturing',
        'restaurant', 'booking', 'repair',
    ];

    public function __construct(private readonly PlanService $plans) {}

    /**
     * Fixed local prices per currency (monthly, yearly), set by the platform,
     * never converted at run time. Yearly is about ten months. The
     * currencies are those the billing providers charge: USD (the fallback,
     * §11.6), NGN, GHS, KES, ZAR (Paystack, Flutterwave), GBP, EUR (Stripe,
     * Flutterwave).
     */
    private const array PRICES = [
        'basic' => [
            'USD' => ['20.00', '200.00'], 'NGN' => ['15000.00', '150000.00'], 'GHS' => ['250.00', '2500.00'],
            'KES' => ['2500.00', '25000.00'], 'ZAR' => ['350.00', '3500.00'], 'GBP' => ['16.00', '160.00'], 'EUR' => ['18.00', '180.00'],
        ],
        'standard' => [
            'USD' => ['39.00', '380.00'], 'NGN' => ['29000.00', '290000.00'], 'GHS' => ['480.00', '4800.00'],
            'KES' => ['4900.00', '49000.00'], 'ZAR' => ['690.00', '6900.00'], 'GBP' => ['31.00', '310.00'], 'EUR' => ['36.00', '360.00'],
        ],
        'premium' => [
            'USD' => ['59.00', '600.00'], 'NGN' => ['45000.00', '450000.00'], 'GHS' => ['750.00', '7500.00'],
            'KES' => ['7500.00', '75000.00'], 'ZAR' => ['1050.00', '10500.00'], 'GBP' => ['47.00', '470.00'], 'EUR' => ['55.00', '550.00'],
        ],
    ];

    public function run(): void
    {
        foreach ($this->catalogue() as $slug => $definition) {
            DB::connection('landlord')->transaction(function () use ($slug, $definition): void {
                $plan = Plan::query()->where('slug', $slug)->first();

                if ($plan === null) {
                    $plan = $this->plans->createPlan([
                        'name' => $definition['name'],
                        'slug' => $slug,
                        'tagline' => $definition['tagline'],
                        'is_active' => true,
                        'is_public' => true,
                        'is_recommended' => false,
                        'sort_order' => $definition['sort_order'],
                    ]);

                    foreach ($definition['features'] as $feature) {
                        $this->plans->attachFeatureToPlan($plan, $feature);
                    }

                    foreach ($definition['limits'] as $key => $value) {
                        $this->plans->setPlanLimit($plan, $key, $value);
                    }
                }

                $this->addMissingPrices($plan, self::PRICES[$slug], $definition['trial_days']);
            });
        }
    }

    /**
     * Adds a price only where the plan has none for that currency and
     * interval, active or not: an edited or deliberately retired price is
     * never touched, so re-seeding an existing platform is safe.
     *
     * @param  array<string, array{0: string, 1: string}>  $prices
     */
    private function addMissingPrices(Plan $plan, array $prices, ?int $trialDays): void
    {
        $existing = $plan->prices()->get(['currency_code', 'billing_interval'])
            ->map(static fn ($p): string => $p->currency_code.':'.$p->billing_interval)
            ->all();

        foreach ($prices as $currency => [$monthly, $yearly]) {
            foreach (['monthly' => $monthly, 'yearly' => $yearly] as $interval => $amount) {
                if (! in_array($currency.':'.$interval, $existing, true)) {
                    $this->plans->addPrice($plan, $currency, $interval, $amount, $trialDays);
                }
            }
        }
    }

    /**
     * @return array<string, array{name: string, tagline: string, sort_order: int, trial_days: int|null, features: list<string>, limits: array<string, int|null>}>
     */
    private function catalogue(): array
    {
        return [
            'basic' => [
                'name' => 'Basic',
                'tagline' => 'Sell online',
                'sort_order' => 1,
                'trial_days' => null,
                'features' => self::BASIC_FEATURES,
                'limits' => [
                    'max_users' => 2, 'max_products' => 250, 'max_warehouses' => 1, 'max_orders_per_month' => 500,
                    'max_storage_mb' => 2048, 'max_pos_registers' => 0, 'max_custom_domains' => 0, 'max_custom_fields' => 10,
                    'max_employees' => 0, 'max_sellers' => 0, 'max_api_requests_per_minute' => 600,
                ],
            ],
            'standard' => [
                'name' => 'Standard',
                'tagline' => 'Run the whole store',
                'sort_order' => 2,
                'trial_days' => 0,
                'features' => [...self::BASIC_FEATURES, ...self::STANDARD_FEATURES],
                'limits' => [
                    'max_users' => 10, 'max_products' => 5000, 'max_warehouses' => 3, 'max_orders_per_month' => 5000,
                    'max_storage_mb' => 20480, 'max_pos_registers' => 3, 'max_custom_domains' => 1, 'max_custom_fields' => 50,
                    'max_employees' => 0, 'max_sellers' => 0, 'max_api_requests_per_minute' => 1500,
                ],
            ],
            'premium' => [
                'name' => 'Premium',
                'tagline' => 'Scale across teams and channels',
                'sort_order' => 3,
                'trial_days' => 0,
                'features' => [...self::BASIC_FEATURES, ...self::STANDARD_FEATURES, ...self::PREMIUM_FEATURES],
                'limits' => [
                    'max_users' => 30, 'max_products' => 50000, 'max_warehouses' => 10, 'max_orders_per_month' => null,
                    'max_storage_mb' => 102400, 'max_pos_registers' => 10, 'max_custom_domains' => 5, 'max_custom_fields' => 200,
                    'max_employees' => 100, 'max_sellers' => 100, 'max_api_requests_per_minute' => 3000,
                ],
            ],
        ];
    }
}
