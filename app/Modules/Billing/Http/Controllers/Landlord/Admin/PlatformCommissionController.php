<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Http\Resources\PlatformCommissionResource;
use App\Modules\Billing\Models\PlatformCommission;
use App\Modules\Billing\Services\PlatformCommissionService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The platform's commission ledger across tenants (D-138).
 */
final class PlatformCommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tenant' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([PlatformCommission::PENDING, PlatformCommission::BILLED, PlatformCommission::COLLECTED, PlatformCommission::WAIVED])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = PlatformCommission::query()
            ->when($filters['tenant'] ?? null, static fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency'] ?? null, static fn ($q, $v) => $q->where('currency_code', strtoupper($v)))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success(PlatformCommissionResource::collection($page));
    }

    public function waive(Request $request, PlatformCommission $commission, PlatformCommissionService $commissions): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        /** @var PlatformUser $user */
        $user = $request->user();

        return APIResponse::success(new PlatformCommissionResource($commissions->waive($commission, (int) $user->id, $reason)), 'Commission waived');
    }
}
