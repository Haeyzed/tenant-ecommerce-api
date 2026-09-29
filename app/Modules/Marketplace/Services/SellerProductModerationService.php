<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

/**
 * Seller product moderation (spec §50.3, UD-11): with
 * seller_product_approval_required, a seller's new product is pending and
 * hidden until staff approve it. Edits to an approved product do not
 * re-trigger moderation (A-68).
 */
final readonly class SellerProductModerationService
{
    public function __construct(private NotificationDispatchService $notifications) {}

    public function submit(Product $product): void
    {
        $product->forceFill(['moderation_status' => 'pending', 'moderation_note' => null])->save();
        $seller = Seller::withTrashed()->find($product->seller_id);

        $this->notifications->dispatch('seller.new_product_pending_approval', null, [
            'seller_name' => $seller?->business_name ?? '',
            'product_name' => $product->name,
            'store_name' => Customer::storeName(),
        ]);
    }

    public function approve(Product $product): Product
    {
        $this->assertPending($product);
        $product->forceFill(['moderation_status' => 'approved', 'moderation_note' => null])->save();

        $this->notifications->dispatch('seller.product_approved', $product, [
            'seller_name' => (string) $product->seller?->business_name,
            'product_name' => $product->name,
            'store_name' => Customer::storeName(),
        ]);

        return $product;
    }

    public function reject(Product $product, string $note): Product
    {
        Validator::make(['moderation_note' => $note], ['moderation_note' => ['required', 'string', 'max:1000']])->validate();
        $this->assertPending($product);
        $product->forceFill(['moderation_status' => 'rejected', 'moderation_note' => $note])->save();

        $this->notifications->dispatch('seller.product_rejected', $product, [
            'seller_name' => (string) $product->seller?->business_name,
            'product_name' => $product->name,
            'moderation_note' => $note,
            'store_name' => Customer::storeName(),
        ]);

        return $product;
    }

    /**
     * @param  array{moderation_status?: string, seller_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function listPending(array $filters = []): LengthAwarePaginator
    {
        return Product::query()->with('seller:id,business_name')->whereNotNull('seller_id')
            ->where('moderation_status', $filters['moderation_status'] ?? 'pending')
            ->when(isset($filters['seller_id']), static fn ($q) => $q->where('seller_id', $filters['seller_id']))
            ->orderBy('created_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function assertPending(Product $product): void
    {
        if ($product->seller_id === null || $product->moderation_status !== 'pending') {
            throw ApiException::invalidTransition($product->moderation_status, 'approved');
        }
    }
}
