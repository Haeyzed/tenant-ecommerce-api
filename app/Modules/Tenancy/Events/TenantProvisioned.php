<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant finished provisioning (spec §9.4 step 7). Its listener sends the
 * welcome notification; no business logic hangs off it (§72.4).
 */
final readonly class TenantProvisioned
{
    use Dispatchable;

    public function __construct(public Tenant $tenant) {}
}
