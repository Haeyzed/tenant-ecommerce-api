<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Affiliates\Models\Affiliate;
use Illuminate\Http\Request;

/**
 * Base of the affiliate portal: every query is scoped to the signed-in
 * affiliate (spec §21A.10); records of another affiliate are never found.
 */
abstract class PortalController extends Controller
{
    protected function affiliate(Request $request): Affiliate
    {
        /** @var Affiliate */
        return $request->user();
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->integer('per_page', 25)));
    }
}
