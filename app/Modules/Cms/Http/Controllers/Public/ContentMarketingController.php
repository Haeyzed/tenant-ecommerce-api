<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsBlogCategory;
use App\Modules\Cms\Models\CmsBlogPost;
use App\Modules\Cms\Models\CmsFaq;
use App\Modules\Cms\Models\CmsFaqCategory;
use App\Modules\Cms\Models\CmsTag;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Services\CmsBlogService;
use App\Modules\Cms\Services\CmsFaqService;
use App\Modules\Cms\Services\CmsTestimonialService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public blog, FAQs and testimonials (spec §24.4, §24.10). In the tenant
 * scope the routes carry feature:content_marketing.
 */
final class ContentMarketingController extends Controller
{
    public function blogCategories(CmsBlogService $blog): JsonResponse
    {
        return APIResponse::success($blog->listCategories(true)->map(static fn (CmsBlogCategory $c): array => CmsPresenter::category($c, true))->values());
    }

    public function blogPosts(Request $request, CmsBlogService $blog): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => ['sometimes', 'integer'],
            'tag' => ['sometimes', 'string', 'max:190'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return APIResponse::success($blog->listPosts($filters, true)->through(static fn (CmsBlogPost $p): array => CmsPresenter::post($p, true, false)));
    }

    public function blogPost(string $slug, CmsBlogService $blog): JsonResponse
    {
        $post = $blog->getPostBySlug($slug, true) ?? throw new NotFoundHttpException('Post not found.');

        return APIResponse::success(CmsPresenter::post($post, true));
    }

    public function tags(CmsBlogService $blog): JsonResponse
    {
        return APIResponse::success($blog->listTags()->map(static fn (CmsTag $t): array => CmsPresenter::tag($t))->values());
    }

    public function faqCategories(CmsFaqService $faqs): JsonResponse
    {
        return APIResponse::success($faqs->listCategories(true)->map(static fn (CmsFaqCategory $c): array => CmsPresenter::category($c, true))->values());
    }

    public function faqs(Request $request, CmsFaqService $faqs): JsonResponse
    {
        $categoryId = $request->validate(['category_id' => ['sometimes', 'integer']])['category_id'] ?? null;

        return APIResponse::success($faqs->listFaqs($categoryId === null ? null : (int) $categoryId, true)->map(static fn (CmsFaq $f): array => CmsPresenter::faq($f, true))->values());
    }

    public function testimonials(Request $request, CmsTestimonialService $testimonials): JsonResponse
    {
        $featured = $request->boolean('featured');

        return APIResponse::success($testimonials->listTestimonials(true, $featured)->map(static fn (CmsTestimonial $t): array => CmsPresenter::testimonial($t, true))->values());
    }
}
