<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Reviews\Services\ReviewService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Review moderation (spec §42.3).
 */
final class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string'],
            'product_id' => ['sometimes', 'integer'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->reviews->listReviews($filters)->through(fn (ProductReview $r): array => $this->present($r)));
    }

    public function approve(ProductReview $review): JsonResponse
    {
        return APIResponse::success($this->present($this->reviews->approveReview($review)), 'Review approved');
    }

    public function reject(Request $request, ProductReview $review): JsonResponse
    {
        return APIResponse::success($this->present($this->reviews->rejectReview($review, (string) $request->input('reason', ''))), 'Review rejected');
    }

    public function destroy(ProductReview $review): JsonResponse
    {
        $this->reviews->deleteReview($review);

        return APIResponse::noContent('Review deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProductReview $review): array
    {
        $review->loadMissing(['product:id,name', 'customer:id,name,email']);

        return [
            'id' => $review->id,
            'product' => ['id' => $review->product_id, 'name' => $review->product?->name],
            'customer' => ['id' => $review->customer_id, 'name' => $review->customer?->name, 'email' => $review->customer?->email],
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'status' => $review->status,
            'rejection_reason' => $review->rejection_reason,
            'is_verified_purchase' => $review->is_verified_purchase,
            'created_at' => $review->created_at->toIso8601String(),
        ];
    }
}
