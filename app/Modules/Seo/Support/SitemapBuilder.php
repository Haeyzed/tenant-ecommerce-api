<?php

declare(strict_types=1);

namespace App\Modules\Seo\Support;

use App\Modules\Settings\Services\StorefrontSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Support\FrontendUrl;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * Builds and stores the sitemap of the current scope (spec §24.8, §30.2).
 * Sources are registered by the modules that own public content: the CMS
 * now (pages and posts), the catalogue when it is built (products). Each
 * source yields {path, lastmod} for the scope it is called in.
 *
 * The tenant sitemap is written to the tenant's private disk (isolated
 * under tenants/{id}/), the landlord one under landlord/.
 */
final class SitemapBuilder
{
    public const string TENANT_FILE = 'sitemap.xml';

    public const string LANDLORD_FILE = 'landlord/sitemap.xml';

    /** @var array<string, array<string, Closure(): iterable<array{path: string, lastmod: CarbonInterface|null}>>> scope => name => source */
    private array $sources = ['tenant' => [], 'landlord' => []];

    /**
     * @param  'tenant'|'landlord'  $scope
     * @param  Closure(): iterable<array{path: string, lastmod: CarbonInterface|null}>  $source
     */
    public function register(string $scope, string $name, Closure $source): void
    {
        $this->sources[$scope][$name] = $source;
    }

    public function buildForTenant(Tenant $tenant): string
    {
        $indexing = (bool) app(StorefrontSettingsService::class)->get('robots_indexing_enabled', true);
        $xml = $this->render($indexing ? $this->entries('tenant', static fn (string $path): string => FrontendUrl::storefront($tenant, $path)) : []);

        Storage::disk('local')->put(self::TENANT_FILE, $xml);

        return $xml;
    }

    public function buildForLandlord(): string
    {
        $xml = $this->render($this->entries('landlord', static fn (string $path): string => FrontendUrl::website($path)));

        Storage::disk('local')->put(self::LANDLORD_FILE, $xml);

        return $xml;
    }

    /**
     * @param  Closure(string): string  $absolute
     * @return list<array{loc: string, lastmod: string|null}>
     */
    private function entries(string $scope, Closure $absolute): array
    {
        $entries = [];

        foreach ($this->sources[$scope] as $source) {
            foreach ($source() as $entry) {
                $loc = $absolute($entry['path']);
                $entries[$loc] = ['loc' => $loc, 'lastmod' => $entry['lastmod']?->toAtomString()];
            }
        }

        return array_values($entries);
    }

    /**
     * @param  list<array{loc: string, lastmod: string|null}>  $entries
     */
    private function render(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($entries as $entry) {
            $xml .= '  <url><loc>'.htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'
                .($entry['lastmod'] !== null ? '<lastmod>'.$entry['lastmod'].'</lastmod>' : '')
                ."</url>\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
