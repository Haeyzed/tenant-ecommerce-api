<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantRegistrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:pending_verification,converted,expired'],
            'email' => ['sometimes', 'string', 'max:255'],
        ]);

        $page = TenantRegistration::query()
            ->with('planPrice.plan')
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['email'] ?? null, static fn ($q, $v) => $q->where('email', 'like', '%'.addcslashes(strtolower($v), '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))))
            ->through(static fn (TenantRegistration $r): array => [
                'id' => $r->public_id,
                'business_name' => $r->business_name,
                'slug' => $r->slug,
                'owner_name' => $r->owner_name,
                'email' => $r->email,
                'country_id' => $r->country_id,
                'default_currency' => $r->default_currency,
                'plan' => $r->planPrice->plan->slug,
                'billing_interval' => $r->planPrice->billing_interval,
                'status' => $r->status,
                'verification_attempts' => $r->verification_attempts,
                'verification_expires_at' => $r->verification_expires_at->toIso8601String(),
                'verified_at' => $r->verified_at?->toIso8601String(),
                'tenant_id' => $r->tenant_id,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return APIResponse::success($page);
    }
}
