<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Product reviews (spec §42.1). A customer reviews a product once (a new
 * submission replaces it); moderation and the verified-purchase rule are
 * tenant settings. rating_average and rating_count follow approved reviews.
 */
final readonly class ReviewService
{
    public function __construct(
        private TenantSettingsService $settings,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data  rating, title, body
     */
    public function submitReview(Customer $customer, Product $product, array $data): ProductReview
    {
        $validated = validator($data, [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
        ])->validate();

        $orderId = $this->verifiedOrderId($customer, $product);

        if ($orderId === null && (bool) $this->settings->get('reviews_require_verified_purchase', false)) {
            throw ApiException::unprocessable('verified_purchase_required', 'Only customers who bought this product can review it.');
        }

        $moderated = (bool) $this->settings->get('review_moderation_required', true);

        $review = DB::connection('tenant')->transaction(function () use ($customer, $product, $validated, $orderId, $moderated): ProductReview {
            /** @var ProductReview $review */
            $review = ProductReview::query()->where('product_id', $product->id)->where('customer_id', $customer->id)->lockForUpdate()->first() ?? new ProductReview;

            $review->forceFill([
                'product_id' => $product->id,
                'customer_id' => $customer->id,
                'order_id' => $orderId,
                'is_verified_purchase' => $orderId !== null,
                'rating' => (int) $validated['rating'],
                'title' => $validated['title'] ?? null,
                'body' => trim((string) $validated['body']),
                'status' => $moderated ? ProductReview::PENDING : ProductReview::APPROVED,
                'rejection_reason' => null,
            ])->save();

            $this->recalculateAggregates($product);

            return $review;
        });

        if ($moderated) {
            $this->notifications->dispatch('review.pending_moderation', null, ['product_name' => $product->name]);
        }

        return $review;
    }

    public function approveReview(ProductReview $review): ProductReview
    {
        $this->moderate($review, ProductReview::APPROVED, null);
        $review->loadMissing('customer', 'product');

        if ($review->customer->anonymized_at === null) {
            $this->notifications->dispatch('review.approved', $review->customer, ['customer_name' => $review->customer->name, 'product_name' => $review->product->name]);
        }

        return $review;
    }

    public function rejectReview(ProductReview $review, string $reason): ProductReview
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();

        return $this->moderate($review, ProductReview::REJECTED, $reason);
    }

    public function deleteReview(ProductReview $review): void
    {
        DB::connection('tenant')->transaction(function () use ($review): void {
            $review->delete();
            $this->recalculateAggregates($review->product);
        });
    }

    /**
     * Approved reviews only, newest or by rating.
     *
     * @param  array{rating?: int, verified?: bool, sort?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, ProductReview>
     */
    public function getReviewsForProduct(Product $product, array $filters = []): LengthAwarePaginator
    {
        return ProductReview::query()->with('customer:id,name,anonymized_at')
            ->where('product_id', $product->id)->where('status', ProductReview::APPROVED)
            ->when($filters['rating'] ?? null, static fn (Builder $q, $v) => $q->where('rating', $v))
            ->when(($filters['verified'] ?? false) === true, static fn (Builder $q) => $q->where('is_verified_purchase', true))
            ->when(($filters['sort'] ?? 'newest') === 'rating_high', static fn (Builder $q) => $q->orderByDesc('rating'))
            ->when(($filters['sort'] ?? 'newest') === 'rating_low', static fn (Builder $q) => $q->orderBy('rating'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * @param  array{status?: string, product_id?: int, rating?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, ProductReview>
     */
    public function listReviews(array $filters): LengthAwarePaginator
    {
        validator($filters, ['status' => ['sometimes', Rule::in([ProductReview::PENDING, ProductReview::APPROVED, ProductReview::REJECTED])]])->validate();

        return ProductReview::query()->with(['product:id,name', 'customer:id,name,email'])
            ->when($filters['status'] ?? null, static fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['product_id'] ?? null, static fn (Builder $q, $v) => $q->where('product_id', $v))
            ->when($filters['rating'] ?? null, static fn (Builder $q, $v) => $q->where('rating', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function getAverageRating(Product $product): string
    {
        return (string) $product->refresh()->rating_average;
    }

    public function recalculateAggregates(Product $product): void
    {
        $stats = ProductReview::query()->where('product_id', $product->id)->where('status', ProductReview::APPROVED)
            ->selectRaw('COUNT(*) as n, AVG(rating) as average')->first();

        DB::connection('tenant')->table('products')->where('id', $product->id)->update([
            'rating_count' => (int) ($stats->n ?? 0),
            'rating_average' => round((float) ($stats->average ?? 0), 2),
        ]);
    }

    private function moderate(ProductReview $review, string $status, ?string $reason): ProductReview
    {
        DB::connection('tenant')->transaction(function () use ($review, $status, $reason): void {
            $review->forceFill(['status' => $status, 'rejection_reason' => $reason])->save();
            $this->recalculateAggregates($review->product);
        });

        return $review;
    }

    /**
     * A delivered or completed order of this customer containing the
     * product (the "verified buyer" badge).
     */
    private function verifiedOrderId(Customer $customer, Product $product): ?int
    {
        $id = Order::query()->where('customer_id', $customer->id)->whereIn('status', [Order::DELIVERED, Order::COMPLETED])
            ->whereHas('items', static fn (Builder $q) => $q->where('product_id', $product->id))
            ->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
