<?php

declare(strict_types=1);

namespace App\Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Seo\Support\SitemapBuilder;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * GET /sitemap.xml on landlord and tenant domains (spec §24.10, §30.2):
 * serves the last generated sitemap, building it once if none exists yet.
 */
final class SitemapController extends Controller
{
    public function __invoke(SitemapBuilder $builder): Response
    {
        $tenant = tenant();
        $file = $tenant instanceof Tenant ? SitemapBuilder::TENANT_FILE : SitemapBuilder::LANDLORD_FILE;
        $disk = Storage::disk('local');

        $xml = $disk->exists($file)
            ? (string) $disk->get($file)
            : ($tenant instanceof Tenant ? $builder->buildForTenant($tenant) : $builder->buildForLandlord());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
