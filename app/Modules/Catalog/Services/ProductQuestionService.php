<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAnswer;
use App\Modules\Catalog\Models\ProductQuestion;
use App\Modules\Customers\Models\Customer;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\TenantSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Pre-purchase questions and answers (spec §29.7). Without moderation both
 * are approved on creation.
 */
final readonly class ProductQuestionService
{
    public function __construct(
        private TenantSettingsService $settings,
        private NotificationDispatchService $notifications,
    ) {}

    public function askQuestion(Customer $customer, Product $product, string $question): ProductQuestion
    {
        validator(['question' => $question], ['question' => ['required', 'string', 'min:5', 'max:2000']])->validate();

        $moderated = $this->moderated();

        /** @var ProductQuestion $created */
        $created = ProductQuestion::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'question' => trim($question),
            'is_approved' => ! $moderated,
            'asked_at' => now(),
        ]);

        if ($moderated) {
            // Audience "admin": the store's owners and admins.
            $this->notifications->dispatch('question.pending_moderation', $created, [
                'product_name' => $product->name,
                'question' => mb_strimwidth($created->question, 0, 280, '…'),
            ]);
        }

        return $created;
    }

    /**
     * @param  Model  $answerer  a staff user or (with marketplace) a seller
     */
    public function answerQuestion(ProductQuestion $question, Model $answerer, string $answer): ProductAnswer
    {
        validator(['answer' => $answer], ['answer' => ['required', 'string', 'min:2', 'max:5000']])->validate();

        /** @var ProductAnswer $created */
        $created = $question->answers()->create([
            'answered_by_type' => $answerer->getMorphClass(),
            'answered_by_id' => $answerer->getKey(),
            'answer' => trim($answer),
            // Staff answers are trusted; seller answers follow moderation.
            'is_approved' => $answerer->getMorphClass() === 'user' || ! $this->moderated(),
        ]);

        if ($created->is_approved) {
            $this->notifyAsker($question, $created);
        }

        return $created;
    }

    public function approveQuestion(ProductQuestion $question): ProductQuestion
    {
        $question->forceFill(['is_approved' => true])->save();

        return $question;
    }

    public function approveAnswer(ProductAnswer $answer): ProductAnswer
    {
        if (! $answer->is_approved) {
            $answer->forceFill(['is_approved' => true])->save();
            $this->notifyAsker($answer->question, $answer);
        }

        return $answer;
    }

    public function deleteQuestion(ProductQuestion $question): void
    {
        $question->delete();
    }

    /**
     * Approved questions with approved answers only.
     *
     * @return LengthAwarePaginator<int, ProductQuestion>
     */
    public function listForProduct(Product $product, int $perPage = 20): LengthAwarePaginator
    {
        return ProductQuestion::query()
            ->where('product_id', $product->id)
            ->where('is_approved', true)
            ->with(['customer:id,name,anonymized_at', 'answers' => static fn ($q) => $q->where('is_approved', true)])
            ->orderByDesc('asked_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{status?: string, product_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, ProductQuestion>
     */
    public function listForModeration(array $filters): LengthAwarePaginator
    {
        validator($filters, ['status' => ['sometimes', Rule::in(['pending', 'approved', 'unanswered'])]])->validate();

        return ProductQuestion::query()
            ->with(['product:id,name', 'customer:id,name,anonymized_at', 'answers'])
            ->when(($filters['status'] ?? null) === 'pending', static fn ($q) => $q->where(static fn ($w) => $w->where('is_approved', false)
                ->orWhereHas('answers', static fn ($a) => $a->where('is_approved', false))))
            ->when(($filters['status'] ?? null) === 'approved', static fn ($q) => $q->where('is_approved', true))
            ->when(($filters['status'] ?? null) === 'unanswered', static fn ($q) => $q->whereDoesntHave('answers'))
            ->when($filters['product_id'] ?? null, static fn ($q, $v) => $q->where('product_id', $v))
            ->orderByDesc('asked_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function moderated(): bool
    {
        return (bool) $this->settings->get('qa_moderation_required', true);
    }

    private function notifyAsker(ProductQuestion $question, ProductAnswer $answer): void
    {
        $question->loadMissing(['customer', 'product']);

        if ($question->customer->anonymized_at !== null) {
            return;
        }

        $this->notifications->dispatch('question.answered', $question->customer, [
            'customer_name' => $question->customer->name,
            'product_name' => $question->product->name,
            'question' => mb_strimwidth($question->question, 0, 500, '…'),
            'answer' => mb_strimwidth($answer->answer, 0, 1000, '…'),
        ]);
    }
}
