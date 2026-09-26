<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Services\ReviewService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Storefront reviews (spec §42.3): approved reviews publicly; submission
 * by signed-in customers. {product} is an id or a slug.
 */
final class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request, string $product): JsonResponse
    {
        $filters = $request->validate([
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'verified' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'in:newest,rating_high,rating_low'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        if (array_key_exists('verified', $filters)) {
            $filters['verified'] = $request->boolean('verified');
        }

        $model = $this->product($product);

        return APIResponse::success($this->reviews->getReviewsForProduct($model, $filters)->through(static fn (ProductReview $r): array => [
            'id' => $r->id,
            'rating' => $r->rating,
            'title' => $r->title,
            'body' => $r->body,
            'author' => $r->customer?->anonymized_at === null ? $r->customer?->name : 'Deleted customer',
            'is_verified_purchase' => $r->is_verified_purchase,
            'created_at' => $r->created_at->toIso8601String(),
        ]), meta: ['rating_average' => (string) $model->rating_average, 'rating_count' => $model->rating_count]);
    }

    public function store(Request $request, string $product): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $review = $this->reviews->submitReview($customer, $this->product($product), $request->all());

        return APIResponse::created([
            'id' => $review->id,
            'rating' => $review->rating,
            'status' => $review->status,
            'is_verified_purchase' => $review->is_verified_purchase,
        ], $review->status === ProductReview::PENDING ? 'Thank you. Your review will appear once approved.' : 'Review published');
    }

    private function product(string $idOrSlug): Product
    {
        $query = Product::query()->visible();

        return (ctype_digit($idOrSlug) ? (clone $query)->whereKey((int) $idOrSlug)->first() : null)
            ?? (clone $query)->where('slug', $idOrSlug)->first()
            ?? throw new NotFoundHttpException('Not found.');
    }
}
