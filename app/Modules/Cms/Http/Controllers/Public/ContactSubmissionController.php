<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Services\ContactSubmissionService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/contact (spec §24.7): always 202, including for a filled
 * honeypot, so bots learn nothing. The authenticated customer is linked
 * once customer authentication exists (Customers module, step 10+).
 */
final class ContactSubmissionController extends Controller
{
    public function store(Request $request, ContactSubmissionService $submissions): JsonResponse
    {
        $submissions->submit($request->all(), $request);

        return APIResponse::accepted(null, 'Thank you. We will get back to you.');
    }
}
