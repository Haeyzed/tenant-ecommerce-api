<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Seo\Jobs\GenerateLandlordSitemap;
use App\Modules\Seo\Jobs\GenerateSitemapForTenant;

/**
 * A publish or unpublish in either scope asks for that scope's sitemap to
 * be rebuilt (spec §24.8). Both jobs are unique and delayed five minutes,
 * so a burst of edits causes one rebuild.
 */
final class SitemapTrigger
{
    public static function requested(): void
    {
        if (CmsScope::isTenant()) {
            GenerateSitemapForTenant::dispatch((string) tenant()?->getTenantKey())->delay(now()->addMinutes(GenerateSitemapForTenant::DEBOUNCE_MINUTES));

            return;
        }

        GenerateLandlordSitemap::dispatch()->delay(now()->addMinutes(GenerateLandlordSitemap::DEBOUNCE_MINUTES));
    }
}
