<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Drivers;

use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use Carbon\CarbonImmutable;

/**
 * One provider's API (spec §69.2). Products go out as channel items,
 * orders come back normalised; every failure is a SocialCommerceException
 * whose message is safe to log (never the token).
 *
 * A channel item: retailer_id (ours: "p{product}" or "p{product}-v{variant}"),
 * group_id, sku, title, description, price (decimal string), currency,
 * quantity, link, image_link.
 *
 * A normalised order: id, currency, email, name, phone, address (name,
 * line1, line2, city, postal_code, country, state), lines (retailer_id or
 * sku, quantity, unit_price, tax), shipping, shipping_tax, total, paid,
 * cancelled, created_at.
 */
interface SocialChannelDriver
{
    public function verify(SocialCommerceAccount $account): void;

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array{external_id: string|null, rejection: string|null}> by retailer_id
     */
    public function upsert(SocialCommerceAccount $account, array $items): array;

    /**
     * @param  list<string>  $externalIds
     */
    public function remove(SocialCommerceAccount $account, array $externalIds): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function orders(SocialCommerceAccount $account, CarbonImmutable $since): array;
}
