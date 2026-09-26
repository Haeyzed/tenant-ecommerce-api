<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Services\PlanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The initial commercial plans of spec §11.14. Keyed by slug: a plan and
 * its children are created only when the slug does not exist, so edits
 * made in production are never reverted.
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

    public function run(): void
    {
        foreach ($this->catalogue() as $slug => $definition) {
            if (Plan::query()->where('slug', $slug)->exists()) {
                continue;
            }

            DB::connection('landlord')->transaction(function () use ($slug, $definition): void {
                $plan = $this->plans->createPlan([
                    'name' => $definition['name'],
                    'slug' => $slug,
                    'tagline' => $definition['tagline'],
                    'is_active' => true,
                    'is_public' => true,
                    'is_recommended' => false,
                    'sort_order' => $definition['sort_order'],
                ]);

                foreach ($definition['prices'] as [$interval, $amount, $trialDays]) {
                    $this->plans->addPrice($plan, 'USD', $interval, $amount, $trialDays);
                }

                foreach ($definition['features'] as $feature) {
                    $this->plans->attachFeatureToPlan($plan, $feature);
                }

                foreach ($definition['limits'] as $key => $value) {
                    $this->plans->setPlanLimit($plan, $key, $value);
                }
            });
        }
    }

    /**
     * @return array<string, array{name: string, tagline: string, sort_order: int, prices: list<array{0: string, 1: string, 2: int|null}>, features: list<string>, limits: array<string, int|null>}>
     */
    private function catalogue(): array
    {
        return [
            'basic' => [
                'name' => 'Basic',
                'tagline' => 'Sell online',
                'sort_order' => 1,
                'prices' => [['monthly', '20.00', null], ['yearly', '200.00', null]],
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
                'prices' => [['monthly', '39.00', 0], ['yearly', '380.00', 0]],
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
                'prices' => [['monthly', '59.00', 0], ['yearly', '600.00', 0]],
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
