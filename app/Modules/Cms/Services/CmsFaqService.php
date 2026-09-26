<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\CmsFaq;
use App\Modules\Cms\Models\CmsFaqCategory;
use App\Modules\Cms\Support\CmsScope;
use App\Modules\Cms\Support\CmsSlug;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FAQ categories and FAQs (spec §24.4).
 */
final readonly class CmsFaqService
{
    /**
     * @return Collection<int, CmsFaqCategory>
     */
    public function listCategories(bool $activeOnly): Collection
    {
        return CmsFaqCategory::query()->when($activeOnly, static fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): CmsFaqCategory
    {
        $validated = $this->validateCategory($data, true);
        $category = new CmsFaqCategory($validated);
        $category->slug = isset($validated['slug']) ? CmsSlug::assertAvailable(CmsFaqCategory::class, $validated['slug']) : CmsSlug::unique(CmsFaqCategory::class, $validated['name']);
        $category->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(CmsFaqCategory $category, array $data): CmsFaqCategory
    {
        $validated = $this->validateCategory($data, false);

        if (isset($validated['slug']) && $validated['slug'] !== $category->slug) {
            $validated['slug'] = CmsSlug::assertAvailable(CmsFaqCategory::class, $validated['slug'], $category->id);
        }

        $category->fill($validated)->save();

        return $category;
    }

    /**
     * FAQs of a deleted category become uncategorised.
     */
    public function deleteCategory(CmsFaqCategory $category): void
    {
        $category->delete();
    }

    /**
     * @return Collection<int, CmsFaq>
     */
    public function listFaqs(?int $categoryId, bool $activeOnly): Collection
    {
        return CmsFaq::query()
            ->when($categoryId !== null, static fn ($q) => $q->where('cms_faq_category_id', $categoryId))
            ->when($activeOnly, static fn ($q) => $q->where('is_active', true)
                ->where(static fn ($q) => $q->whereNull('cms_faq_category_id')
                    ->orWhereIn('cms_faq_category_id', CmsFaqCategory::query()->where('is_active', true)->select('id'))))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFaq(array $data): CmsFaq
    {
        $validated = $this->validateFaq($data, true);
        $validated['sort_order'] ??= (int) CmsFaq::query()->max('sort_order') + 1;

        /** @var CmsFaq $faq */
        $faq = CmsFaq::query()->create($validated);

        return $faq;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateFaq(CmsFaq $faq, array $data): CmsFaq
    {
        $faq->fill($this->validateFaq($data, false))->save();

        return $faq;
    }

    public function deleteFaq(CmsFaq $faq): void
    {
        $faq->delete();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorderFaqs(array $orderedIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $orderedIds)));

        if (CmsFaq::query()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['ordered_ids' => ['Every id must be an existing FAQ.']]);
        }

        DB::connection(CmsScope::current())->transaction(static function () use ($ids): void {
            foreach ($ids as $position => $id) {
                CmsFaq::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateFaq(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return validator($data, [
            'cms_faq_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists(CmsScope::current().'.cms_faq_categories', 'id')],
            'question' => [$req, 'string', 'max:255'],
            'answer' => [$req, 'string', 'max:20000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateCategory(array $data, bool $creating): array
    {
        return validator($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:190'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }
}
