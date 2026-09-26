<?php

declare(strict_types=1);

namespace App\Modules\Legal\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Http\Resources\LegalDocumentResource;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner accepts a re-acceptance version (spec §9.2). Calls the landlord
 * legal service under §6.4; the tenant is always the current one.
 */
final class LegalAcceptanceController extends Controller
{
    public function store(Request $request, LegalDocumentService $legal): JsonResponse
    {
        $validated = $request->validate(['legal_document_id' => ['required', 'integer']]);

        /** @var Tenant $tenant */
        $tenant = tenant();
        /** @var User $user */
        $user = $request->user();

        $pending = $legal->pendingForTenant($tenant)->firstWhere('id', (int) $validated['legal_document_id']);

        if ($pending === null) {
            throw ApiException::unprocessable('legal_document_not_pending', 'This document does not need acceptance.');
        }

        $legal->recordAcceptances([$pending->id], [
            'tenant_id' => (string) $tenant->getTenantKey(),
            'accepted_by_user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ], 'reacceptance', $request);

        return APIResponse::success(LegalDocumentResource::collection($legal->pendingForTenant($tenant)), 'Accepted');
    }
}
