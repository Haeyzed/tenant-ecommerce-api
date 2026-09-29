<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http;

use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\Product;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerGroup;
use App\Modules\Marketplace\Models\SellerLedgerEntry;
use App\Modules\Marketplace\Models\SellerPayout;
use App\Modules\Marketplace\Services\SellerService;

/**
 * Marketplace responses (spec §50.7). A seller sees its effective rate and
 * group read-only; staff also see the audit-relevant fields.
 */
final readonly class MarketplacePresenter
{
    public const string ENTITY = 'seller';

    public function __construct(
        private SellerService $sellers,
        private CatalogPresenter $catalog,
        private CustomFieldService $customFields,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sellerForSelf(Seller $seller): array
    {
        $seller->loadMissing('group');

        return [
            'id' => $seller->id,
            'business_name' => $seller->business_name,
            'contact_name' => $seller->contact_name,
            'email' => $seller->email,
            'phone' => $seller->phone,
            'status' => $seller->status,
            'group' => $seller->group === null ? null : ['id' => $seller->group->id, 'name' => $seller->group->name],
            'effective_commission_rate' => $this->sellers->getEffectiveCommissionRate($seller),
            'approved_at' => $seller->approved_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sellerForAdmin(Seller $seller, bool $detail = false): array
    {
        return [
            ...$this->sellerForSelf($seller),
            'commission_rate' => $seller->commission_rate === null ? null : (string) $seller->commission_rate,
            'rejection_reason' => $seller->rejection_reason,
            'products_count' => $seller->getAttributes()['products_count'] ?? null,
            'last_login_at' => $seller->last_login_at?->toIso8601String(),
            'created_at' => $seller->created_at?->toIso8601String(),
            ...($detail ? ['custom_fields' => $this->customFields->valuesFor($seller, self::ENTITY, CustomFieldService::ADMIN)] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function group(SellerGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'default_commission_rate' => $group->default_commission_rate === null ? null : (string) $group->default_commission_rate,
            'sellers_count' => $group->getAttributes()['sellers_count'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function product(Product $product, bool $detail): array
    {
        return [
            ...$this->catalog->adminProduct($product, $detail),
            'seller_id' => $product->seller_id,
            'seller' => $product->relationLoaded('seller') && $product->seller !== null ? ['id' => $product->seller->id, 'business_name' => $product->seller->business_name] : null,
            'moderation_note' => $product->moderation_note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(SellerLedgerEntry $e): array
    {
        return [
            'id' => $e->id,
            'entry_type' => $e->entry_type,
            'order' => ['id' => $e->order_id, 'order_number' => $e->relationLoaded('order') ? $e->order?->order_number : null],
            'order_item_id' => $e->order_item_id,
            'source_type' => $e->source_type,
            'gross_amount' => (string) $e->gross_amount,
            'commission_rate_applied' => (string) $e->commission_rate_applied,
            'commission_amount' => (string) $e->commission_amount,
            'net_payable' => (string) $e->net_payable,
            'available_at' => $e->available_at?->toIso8601String(),
            'seller_payout_id' => $e->seller_payout_id,
            'created_at' => $e->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payout(SellerPayout $p): array
    {
        return [
            'id' => $p->id,
            'seller_id' => $p->seller_id,
            'period_start' => $p->period_start->toDateString(),
            'period_end' => $p->period_end->toDateString(),
            'gross_amount' => (string) $p->gross_amount,
            'commission_amount' => (string) $p->commission_amount,
            'net_payable' => (string) $p->net_payable,
            'status' => $p->status,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'reference' => $p->reference,
            'notes' => $p->notes,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
