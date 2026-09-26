<?php

declare(strict_types=1);

namespace App\Modules\ModuleNotices\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\ModuleNotices\Http\Resources\ModuleNoticeResource;
use App\Modules\ModuleNotices\Models\ModuleNotice;
use App\Modules\ModuleNotices\Services\ModuleNoticeService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ModuleNoticeController extends Controller
{
    public function __construct(private readonly ModuleNoticeService $notices) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'module_key' => ['sometimes', 'string', 'max:64'],
            'tenant_id' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $page = ModuleNotice::query()
            ->when($filters['module_key'] ?? null, static fn ($q, $v) => $q->where('module_key', $v))
            ->when($filters['tenant_id'] ?? null, static fn ($q, $v) => $q->where('tenant_id', $v))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('starts_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return APIResponse::success(ModuleNoticeResource::collection($page));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(new ModuleNoticeResource($this->notices->createNotice($request->all())));
    }

    public function update(Request $request, ModuleNotice $notice): JsonResponse
    {
        return APIResponse::success(new ModuleNoticeResource($this->notices->updateNotice($notice, $request->all())), 'Notice updated');
    }

    /**
     * Notices are deactivated, never deleted, so the history stays.
     */
    public function destroy(ModuleNotice $notice): JsonResponse
    {
        $this->notices->deactivateNotice($notice);

        return APIResponse::noContent('Notice deactivated');
    }
}
