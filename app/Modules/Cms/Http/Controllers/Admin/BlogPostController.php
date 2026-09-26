<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsBlogPost;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Services\CmsBlogService;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BlogPostController extends Controller
{
    public function __construct(private readonly CmsBlogService $blog) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([CmsPage::DRAFT, CmsPage::PUBLISHED])],
            'category_id' => ['sometimes', 'integer'],
            'tag' => ['sometimes', 'string', 'max:190'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->blog->listPosts($filters)->through(static fn (CmsBlogPost $p): array => CmsPresenter::post($p, false, false)));
    }

    public function show(CmsBlogPost $post): JsonResponse
    {
        return APIResponse::success(CmsPresenter::post($post->load(['category', 'tags']), false));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Model $author */
        $author = $request->user();

        return APIResponse::created(CmsPresenter::post($this->blog->createPost($request->all(), $author), false), 'Post created');
    }

    public function update(Request $request, CmsBlogPost $post): JsonResponse
    {
        return APIResponse::success(CmsPresenter::post($this->blog->updatePost($post, $request->all()), false), 'Post updated');
    }

    public function destroy(CmsBlogPost $post): JsonResponse
    {
        $this->blog->deletePost($post);

        return APIResponse::noContent('Post deleted');
    }

    public function publish(CmsBlogPost $post): JsonResponse
    {
        return APIResponse::success(CmsPresenter::post($this->blog->publishPost($post)->load(['category', 'tags']), false), 'Post published');
    }

    public function unpublish(CmsBlogPost $post): JsonResponse
    {
        return APIResponse::success(CmsPresenter::post($this->blog->unpublishPost($post)->load(['category', 'tags']), false), 'Post unpublished');
    }
}
