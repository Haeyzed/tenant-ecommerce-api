<?php

declare(strict_types=1);

namespace App\Modules\Reviews;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\Reviews\Models\ProductReview;
use App\Modules\Wishlist\Models\WishlistItem;
use Illuminate\Support\ServiceProvider;

/**
 * Registers reviews and the wishlist with customer privacy (§26.4).
 * Erasure removes the wishlist; reviews stay, shown as "Deleted customer".
 */
final class ReviewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomerPrivacyRegistry::class, static function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('wishlist', static fn (Customer $customer) => WishlistItem::query()->where('customer_id', $customer->id)->delete());

            $privacy->registerSection('reviews', static fn (Customer $customer): iterable => ProductReview::query()->with('product:id,name')
                ->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (ProductReview $r): array => [
                    'product' => $r->product?->name, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body,
                    'status' => $r->status, 'created_at' => $r->created_at->toIso8601String(),
                ]));

            $privacy->registerSection('wishlist', static fn (Customer $customer): iterable => WishlistItem::query()->with('product:id,name')
                ->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (WishlistItem $i): array => ['product' => $i->product?->name, 'added_at' => $i->created_at->toIso8601String()]));
        });
    }
}
