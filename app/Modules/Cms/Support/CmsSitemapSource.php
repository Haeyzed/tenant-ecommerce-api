<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\CmsBlogPost;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * CMS entries of the sitemap (spec §24.8): published pages with
 * robots = index_follow, and published posts (in the tenant scope only
 * while content_marketing is enabled).
 */
final class CmsSitemapSource
{
    /**
     * @return iterable<array{path: string, lastmod: CarbonInterface|null}>
     */
    public static function entries(): iterable
    {
        foreach (CmsPage::query()->where('status', CmsPage::PUBLISHED)->where('robots', 'index_follow')->orderBy('id')->cursor() as $page) {
            yield ['path' => CmsPaths::page($page), 'lastmod' => $page->updated_at];
        }

        $tenant = tenant();

        if ($tenant instanceof Tenant && ! app(FeatureAccessService::class)->tenantCanAccess($tenant, 'content_marketing')) {
            return;
        }

        foreach (CmsBlogPost::query()->where('status', CmsPage::PUBLISHED)->orderBy('id')->cursor() as $post) {
            yield ['path' => CmsPaths::post($post), 'lastmod' => $post->updated_at];
        }
    }

    /**
     * Whether published content changed after the given moment.
     */
    public static function changedSince(?int $timestamp): bool
    {
        if ($timestamp === null) {
            return true;
        }

        $since = date('Y-m-d H:i:s', $timestamp);

        return CmsPage::query()->where('updated_at', '>', $since)->exists()
            || CmsBlogPost::query()->where('updated_at', '>', $since)->exists();
    }
}
