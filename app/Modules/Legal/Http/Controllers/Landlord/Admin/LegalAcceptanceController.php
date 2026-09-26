<?php

declare(strict_types=1);

namespace App\Modules\Legal\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\LegalAcceptance;
use App\Modules\Legal\Models\LegalDocument;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LegalAcceptanceController extends Controller
{
    public function index(Request $request, LegalDocument $document): JsonResponse
    {
        $page = LegalAcceptance::query()
            ->where('legal_document_id', $document->id)
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))))
            ->through(static fn (LegalAcceptance $a): array => [
                'id' => $a->id,
                'tenant_id' => $a->tenant_id,
                'affiliate_id' => $a->affiliate_id,
                'accepted_by_name' => $a->accepted_by_name,
                'accepted_by_email' => $a->accepted_by_email,
                'context' => $a->context,
                'ip_address' => $a->ip_address,
                'accepted_at' => $a->accepted_at->toIso8601String(),
            ]);

        return APIResponse::success($page);
    }
}
