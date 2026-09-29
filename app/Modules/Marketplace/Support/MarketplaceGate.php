<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Whether seller products can be sold right now (spec §11.5): while the
 * marketplace is not enabled they leave the storefront catalogue, carts
 * and checkout, because a sale could not be credited to the seller. They
 * stay in the admin catalogue.
 */
final class MarketplaceGate
{
    public static function sellerProductsSellable(): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && app(FeatureAccessService::class)->state($tenant, 'marketplace') === ModuleState::Enabled;
    }
}
