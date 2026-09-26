<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Support\SitemapTrigger;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The category tree and product membership (spec §27.1, §27.4).
 */
final readonly class CategoryService
{
    /**
     * @param  array{active_only?: bool, tree?: bool}  $filters
     * @return Collection<int, Category>|list<array<string, mixed>>
     */
    public function listCategories(array $filters = []): Collection|array
    {
        $categories = Category::query()
            ->when($filters['active_only'] ?? false, static fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ($filters['tree'] ?? false) ? $this->tree($categories, null) : $categories;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): Category
    {
        $validated = $this->validate($data, null);

        /** @var Category $category */
        $category = Category::query()->create($validated);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data): Category
    {
        $validated = $this->validate($data, $category);

        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] !== null && in_array((int) $validated['parent_id'], $this->subtreeIds($category), true)) {
            throw ValidationException::withMessages(['parent_id' => ['A category cannot move under itself or its descendants.']]);
        }

        $category->fill($validated)->save();
        SitemapTrigger::requested();

        return $category;
    }

    public function deleteCategory(Category $category): void
    {
        if (Category::query()->where('parent_id', $category->id)->exists()) {
            throw ApiException::unprocessable('category_has_children', 'Move or delete the subcategories first.');
        }

        DB::connection('tenant')->transaction(function () use ($category): void {
            $productIds = DB::connection('tenant')->table('product_categories')->where('category_id', $category->id)->where('is_primary', true)->pluck('product_id');
            $category->delete();

            // Products that lost their primary category get their next one.
            foreach ($productIds as $productId) {
                $this->ensurePrimary((int) $productId);
            }
        });

        SitemapTrigger::requested();
    }

    /**
     * @param  list<int>  $orderedIds  siblings in their new order
     */
    public function reorderCategories(array $orderedIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $orderedIds)));

        if (Category::query()->whereKey($ids)->distinct()->count('parent_id') > 1
            || Category::query()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['ordered_ids' => ['Reorder existing categories that share one parent.']]);
        }

        DB::connection('tenant')->transaction(static function () use ($ids): void {
            foreach ($ids as $position => $id) {
                Category::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });
    }

    /**
     * Syncs the product's categories. When it has any, exactly one is
     * primary: the given one, else the first id.
     *
     * @param  list<int>  $categoryIds
     */
    public function attachCategories(Product $product, array $categoryIds, ?int $primaryCategoryId = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));

        if (Category::query()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['category_ids' => ['Every category must exist.']]);
        }

        if ($primaryCategoryId !== null && ! in_array($primaryCategoryId, $ids, true)) {
            throw ValidationException::withMessages(['primary_category_id' => ['The primary category must be one of the product\'s categories.']]);
        }

        $primary = $primaryCategoryId ?? ($ids[0] ?? null);
        $product->categories()->sync(array_fill_keys($ids, ['is_primary' => false]));

        if ($primary !== null) {
            $product->categories()->updateExistingPivot($primary, ['is_primary' => true]);
        }
    }

    public function detachCategory(Product $product, Category $category): void
    {
        $product->categories()->detach($category->id);
        $this->ensurePrimary($product->id);
    }

    public function setPrimaryCategory(Product $product, Category $category): void
    {
        if (! $product->categories()->whereKey($category->id)->exists()) {
            throw ApiException::unprocessable('category_not_attached', 'Attach the category to the product first.');
        }

        DB::connection('tenant')->transaction(static function () use ($product, $category): void {
            DB::connection('tenant')->table('product_categories')->where('product_id', $product->id)->update(['is_primary' => false]);
            DB::connection('tenant')->table('product_categories')->where('product_id', $product->id)->where('category_id', $category->id)->update(['is_primary' => true]);
        });
    }

    /**
     * @return Collection<int, Category>
     */
    public function getCategoriesForProduct(Product $product): Collection
    {
        return $product->categories()->orderByDesc('product_categories.is_primary')->orderBy('name')->get();
    }

    /**
     * The category and all its descendants (for "category includes its
     * subcategories" filtering).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function withDescendants(array $ids): array
    {
        $all = $ids;
        $frontier = $ids;

        while ($frontier !== []) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->whereNotIn('id', $all)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $all = [...$all, ...$frontier];
        }

        return array_values(array_unique($all));
    }

    /**
     * @return list<int>
     */
    private function subtreeIds(Category $category): array
    {
        return $this->withDescendants([$category->id]);
    }

    private function ensurePrimary(int $productId): void
    {
        $table = DB::connection('tenant')->table('product_categories')->where('product_id', $productId);

        if (! (clone $table)->where('is_primary', true)->exists()) {
            $first = (clone $table)->orderBy('category_id')->value('category_id');

            if ($first !== null) {
                DB::connection('tenant')->table('product_categories')->where('product_id', $productId)->where('category_id', $first)->update(['is_primary' => true]);
            }
        }
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $categories, ?int $parentId): array
    {
        return $categories->where('parent_id', $parentId)->map(fn (Category $c): array => [
            'category' => $c,
            'children' => $this->tree($categories, $c->id),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?Category $existing): array
    {
        $req = $existing === null ? 'required' : 'sometimes';

        return validator($data, [
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.categories', 'id')],
            'name' => [$req, 'string', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenant.categories', 'slug')->ignore($existing?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'meta_keywords' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();
    }
}
