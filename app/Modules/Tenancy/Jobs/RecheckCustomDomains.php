<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Enums\DomainStatus;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Services\TenantDomainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The scheduled custom domain run (spec §7.5): hourly, pending domains are
 * verified and verified domains awaiting TLS are probed; with $daily, active
 * and misconfigured domains are re-checked as well.
 */
final class RecheckCustomDomains implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [300];

    public int $timeout = 1800;

    public int $uniqueFor = 3000;

    public function __construct(public readonly bool $daily = false)
    {
        $this->onQueue('landlord-default');
    }

    public function uniqueId(): string
    {
        return $this->daily ? 'daily' : 'hourly';
    }

    public function handle(TenantDomainService $domains): void
    {
        $this->each(Domain::query()->where('type', 'custom')->where('status', DomainStatus::PendingVerification->value), $domains->verify(...));

        $this->each(
            Domain::query()->where('type', 'custom')->where('status', DomainStatus::Verified->value)->where('tls_status', 'pending'),
            $domains->checkTls(...),
        );

        if ($this->daily) {
            $this->each(
                Domain::query()->where('type', 'custom')->whereIn('status', [DomainStatus::Active->value, DomainStatus::Misconfigured->value]),
                $domains->recheck(...),
            );
        }
    }

    /**
     * @param  Builder<Domain>  $query
     */
    private function each($query, callable $check): void
    {
        $query->chunkById(100, static function ($rows) use ($check): void {
            foreach ($rows as $domain) {
                try {
                    $check($domain);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
