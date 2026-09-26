<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\CmsBlogPost;
use App\Modules\Cms\Models\CmsPage;

/**
 * The public URL paths the frontends serve for CMS content
 * (config cms.paths), used by resolved menus and sitemaps.
 */
final class CmsPaths
{
    public static function page(CmsPage $page): string
    {
        return $page->is_homepage ? (string) config('cms.paths.home', '/') : str_replace('{slug}', $page->slug, (string) config('cms.paths.page'));
    }

    public static function post(CmsBlogPost $post): string
    {
        return str_replace('{slug}', $post->slug, (string) config('cms.paths.blog_post'));
    }

    public static function blog(): string
    {
        return (string) config('cms.paths.blog', '/blog');
    }
}
