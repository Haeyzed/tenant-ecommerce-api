<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductQuestion;
use App\Modules\Catalog\Services\ProductQuestionService;
use App\Modules\Marketplace\Models\Seller;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sellers answer questions about their own products (spec §29.8, §50.3);
 * their answers follow the store's question moderation.
 */
final class ProductQuestionController extends Controller
{
    public function __construct(private readonly ProductQuestionService $questions) {}

    /**
     * Body: answer.
     */
    public function answer(Request $request, ProductQuestion $question): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        $question->loadMissing('product');

        if ($question->product === null || $question->product->seller_id !== $seller->id) {
            throw new NotFoundHttpException;
        }

        $answer = $this->questions->answerQuestion($question, $seller, (string) $request->input('answer', ''));

        return APIResponse::created(['id' => $answer->id, 'answer' => $answer->answer, 'is_approved' => $answer->is_approved], 'Answer posted');
    }
}
