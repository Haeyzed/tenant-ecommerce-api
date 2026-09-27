<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Metrics\CatalogMetrics;
use App\Modules\Customers\Metrics\CustomerMetrics;
use App\Modules\Dashboard\Services\Tenant\TenantDashboardService;
use App\Modules\Expenses\Metrics\ExpenseMetrics;
use App\Modules\Inventory\Metrics\InventoryMetrics;
use App\Modules\Orders\Metrics\OrderMetrics;
use App\Modules\Payments\Metrics\PaymentMetrics;
use App\Modules\Promotions\Metrics\PromotionMetrics;
use App\Modules\Returns\Metrics\ReturnMetrics;
use App\Modules\Reviews\Metrics\ReviewMetrics;
use App\Modules\Shipping\Metrics\ShipmentMetrics;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\MetricsScope;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;

/**
 * The contextual KPI strips of the core list screens (spec §44.4), one
 * action for every `{resource}/metrics` route (the route default names the
 * resource). The derived permission is `{resource}.view`; each strip comes
 * from the resource module's own metrics provider, within the staff scope.
 */
final class ResourceMetricsController extends Controller
{
    /** resource => [provider, method] */
    private const array STRIPS = [
        'products' => [CatalogMetrics::class, 'productsStrip'],
        'categories' => [CatalogMetrics::class, 'categoriesStrip'],
        'orders' => [OrderMetrics::class, 'contextual'],
        'customers' => [CustomerMetrics::class, 'contextual'],
        'inventory' => [InventoryMetrics::class, 'contextual'],
        'stock-transfers' => [InventoryMetrics::class, 'transfersStrip'],
        'promotions' => [PromotionMetrics::class, 'promotionsStrip'],
        'coupons' => [PromotionMetrics::class, 'couponsStrip'],
        'order-payments' => [PaymentMetrics::class, 'contextual'],
        'returns' => [ReturnMetrics::class, 'contextual'],
        'shipments' => [ShipmentMetrics::class, 'contextual'],
        'reviews' => [ReviewMetrics::class, 'contextual'],
    ];

    /** Strips of optional modules; each module registers its route behind its feature. */
    private const array MODULE_STRIPS = [
        'expenses' => [ExpenseMetrics::class, 'contextual'],
    ];

    public function metrics(MetricsRangeRequest $request, TenantDashboardService $dashboard, Container $container): JsonResponse
    {
        $resource = (string) $request->route()?->defaults['metrics_resource'];
        [$class, $method] = self::STRIPS[$resource] ?? self::MODULE_STRIPS[$resource];
        $provider = $container->make($class);
        $parameters = [];

        // The inventory strip may be narrowed to one warehouse (§44.4).
        if ($resource === 'inventory') {
            $warehouseId = $request->validate(['warehouse_id' => ['sometimes', 'integer', 'min:1']])['warehouse_id'] ?? null;
            $parameters[] = 'warehouse:'.($warehouseId ?? 'all');
            $compute = static fn (DateRange $range, MetricsScope $scope): array => $provider->{$method}($range, $scope, $warehouseId === null ? null : (int) $warehouseId);
        } else {
            $compute = Closure::fromCallable([$provider, $method]);
        }

        /** @var User $user */
        $user = $request->user();
        $result = $dashboard->contextualKpis($resource, $dashboard->range($request->rangeInput()), $user, $compute, $parameters);

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    /**
     * @return list<string>
     */
    public static function resources(): array
    {
        return array_keys(self::STRIPS);
    }
}
