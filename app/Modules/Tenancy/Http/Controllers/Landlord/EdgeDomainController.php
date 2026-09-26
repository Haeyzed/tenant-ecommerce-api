<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Services\TenantDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The edge proxy's on-demand TLS check (spec §7.5 rule 2): 200 only for a
 * verified custom domain of an active tenant, else 404.
 */
final class EdgeDomainController extends Controller
{
    public function allowed(Request $request, TenantDomainService $domains): JsonResponse
    {
        $host = (string) $request->query('domain', '');

        abort_unless($host !== '' && $domains->allowsCertificate($host), 404);

        return response()->json(['allowed' => true]);
    }
}
