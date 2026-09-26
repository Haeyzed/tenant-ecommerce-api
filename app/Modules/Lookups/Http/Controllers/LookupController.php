<?php

declare(strict_types=1);

namespace App\Modules\Lookups\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Lookups\Support\LookupRegistry;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one generic lookup endpoint of every context (spec §45). The route
 * carries its context as a default; an unknown key is 404.
 */
final class LookupController extends Controller
{
    public function show(Request $request, string $key, LookupRegistry $lookups): JsonResponse
    {
        $context = (string) $request->route()?->defaults['lookup_context'];

        abort_unless($lookups->has($context, $key), 404);

        return APIResponse::success($lookups->resolve($context, $key, $request));
    }
}
