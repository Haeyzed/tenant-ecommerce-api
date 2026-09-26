<?php

declare(strict_types=1);

namespace App\Modules\Seo\Jobs;

use App\Modules\Seo\Support\SitemapBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Rebuilds the landlord website's sitemap (spec §24.8): unique, delayed
 * five minutes after a landlord publish or unpublish.
 */
final class GenerateLandlordSitemap implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int DEBOUNCE_MINUTES = 5;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('landlord-default');
    }

    public function handle(SitemapBuilder $builder): void
    {
        $builder->buildForLandlord();
    }
}
