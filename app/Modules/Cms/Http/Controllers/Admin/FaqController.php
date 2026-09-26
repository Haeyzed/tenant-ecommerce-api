<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsFaq;
use App\Modules\Cms\Services\CmsFaqService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FaqController extends Controller
{
    public function __construct(private readonly CmsFaqService $faqs) {}

    public function index(Request $request): JsonResponse
    {
        $categoryId = $request->validate(['category_id' => ['sometimes', 'integer']])['category_id'] ?? null;

        return APIResponse::success($this->faqs->listFaqs($categoryId === null ? null : (int) $categoryId, false)->map(static fn (CmsFaq $f): array => CmsPresenter::faq($f, false))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(CmsPresenter::faq($this->faqs->createFaq($request->all()), false), 'FAQ created');
    }

    public function update(Request $request, CmsFaq $faq): JsonResponse
    {
        return APIResponse::success(CmsPresenter::faq($this->faqs->updateFaq($faq, $request->all()), false), 'FAQ updated');
    }

    public function destroy(CmsFaq $faq): JsonResponse
    {
        $this->faqs->deleteFaq($faq);

        return APIResponse::noContent('FAQ deleted');
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ordered_ids' => ['required', 'array', 'min:1'], 'ordered_ids.*' => ['integer']])['ordered_ids'];
        $this->faqs->reorderFaqs($ids);

        return APIResponse::success(null, 'FAQs reordered');
    }
}
