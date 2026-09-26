<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Services\CatalogReferenceService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product tags (spec §29.8).
 */
final class TagController extends Controller
{
    public function __construct(private readonly CatalogReferenceService $references) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->references->listTags()->map(static fn (Tag $t): array => $t->only(['id', 'name', 'slug']))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->references->createTag($request->all())->only(['id', 'name', 'slug']), 'Tag created');
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $this->references->deleteTag($tag);

        return APIResponse::noContent('Tag deleted');
    }
}
