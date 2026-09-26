<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Services\TenantDomainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks that the edge serves a certificate for one verified custom domain (spec §7.5).
 */
final class CheckCustomDomainTls implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $domainId)
    {
        $this->onQueue('landlord-default');
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(TenantDomainService $domains): void
    {
        $domain = Domain::query()->find($this->domainId);

        if ($domain !== null) {
            $domains->checkTls($domain);
        }
    }
}
