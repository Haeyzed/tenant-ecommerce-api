<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsTag;
use App\Modules\Cms\Services\CmsBlogService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

final class TagController extends Controller
{
    public function __construct(private readonly CmsBlogService $blog) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->blog->listTags()->map(static fn (CmsTag $t): array => CmsPresenter::tag($t))->values());
    }

    public function destroy(CmsTag $tag): JsonResponse
    {
        $this->blog->deleteTag($tag);

        return APIResponse::noContent('Tag deleted');
    }
}
