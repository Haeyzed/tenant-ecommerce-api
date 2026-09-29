<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\ProductSubscriptions\Models\ProductSubscriptionPlan;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The delivery schedules a product offers (spec §55.3). A schedule that
 * already exists is reactivated rather than duplicated.
 */
final readonly class ProductSubscriptionPlanService
{
    /**
     * @param  array<string, mixed>  $data  interval, interval_count?
     */
    public function createPlan(Product $product, array $data): ProductSubscriptionPlan
    {
        $validated = Validator::make($data, [
            'interval' => ['required', Rule::in(ProductSubscriptionPlan::INTERVALS)],
            'interval_count' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ])->validate();

        if (! $product->is_subscribable) {
            throw ApiException::unprocessable('product_not_subscribable', 'Mark the product as subscribable first.');
        }

        $schedule = ['product_id' => $product->id, 'interval' => $validated['interval'], 'interval_count' => (int) ($validated['interval_count'] ?? 1)];
        $plan = ProductSubscriptionPlan::query()->where($schedule)->first() ?? new ProductSubscriptionPlan;
        $plan->forceFill([...$schedule, 'is_active' => true])->save();

        return $plan;
    }

    /**
     * New subscriptions stop; existing ones keep renewing on it.
     */
    public function deactivatePlan(ProductSubscriptionPlan $plan): ProductSubscriptionPlan
    {
        $plan->forceFill(['is_active' => false])->save();

        return $plan;
    }

    /**
     * @return Collection<int, ProductSubscriptionPlan>
     */
    public function listPlansForProduct(Product $product, bool $activeOnly = false): Collection
    {
        return ProductSubscriptionPlan::query()->where('product_id', $product->id)
            ->when($activeOnly, static fn ($q) => $q->where('is_active', true))
            ->orderByRaw("FIELD(`interval`, 'weekly', 'biweekly', 'monthly', 'quarterly')")->orderBy('interval_count')
            ->get();
    }
}
