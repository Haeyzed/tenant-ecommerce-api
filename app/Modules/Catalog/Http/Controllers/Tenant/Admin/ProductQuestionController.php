<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\ProductAnswer;
use App\Modules\Catalog\Models\ProductQuestion;
use App\Modules\Catalog\Services\ProductQuestionService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Q&A moderation (spec §29.8).
 */
final class ProductQuestionController extends Controller
{
    public function __construct(
        private readonly ProductQuestionService $questions,
        private readonly CatalogPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string'],
            'product_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->questions->listForModeration($filters)->through(fn (ProductQuestion $q): array => $this->presenter->question($q, false)));
    }

    public function approve(ProductQuestion $question): JsonResponse
    {
        return APIResponse::success($this->presenter->question($this->questions->approveQuestion($question)->load('answers', 'product', 'customer'), false), 'Question approved');
    }

    public function answer(Request $request, ProductQuestion $question): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $this->questions->answerQuestion($question, $staff, (string) $request->input('answer', ''));

        return APIResponse::created($this->presenter->question($question->load('answers', 'product', 'customer'), false), 'Answer posted');
    }

    public function approveAnswer(ProductAnswer $answer): JsonResponse
    {
        $this->questions->approveAnswer($answer);

        return APIResponse::success($this->presenter->question($answer->question->load('answers', 'product', 'customer'), false), 'Answer approved');
    }

    public function destroy(ProductQuestion $question): JsonResponse
    {
        $this->questions->deleteQuestion($question);

        return APIResponse::noContent('Question deleted');
    }
}
