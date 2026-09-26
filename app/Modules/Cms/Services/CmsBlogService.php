<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\CmsBlogCategory;
use App\Modules\Cms\Models\CmsBlogPost;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsTag;
use App\Modules\Cms\Support\CmsScope;
use App\Modules\Cms\Support\CmsSlug;
use App\Modules\Cms\Support\SitemapTrigger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Blog categories, posts and tags (spec §24.4).
 */
final readonly class CmsBlogService
{
    /**
     * @return Collection<int, CmsBlogCategory>
     */
    public function listCategories(bool $activeOnly): Collection
    {
        return CmsBlogCategory::query()->when($activeOnly, static fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): CmsBlogCategory
    {
        $validated = $this->validateTaxonomy($data, true);
        $category = new CmsBlogCategory($validated);
        $category->slug = isset($validated['slug']) ? CmsSlug::assertAvailable(CmsBlogCategory::class, $validated['slug']) : CmsSlug::unique(CmsBlogCategory::class, $validated['name']);
        $category->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(CmsBlogCategory $category, array $data): CmsBlogCategory
    {
        $validated = $this->validateTaxonomy($data, false);

        if (isset($validated['slug']) && $validated['slug'] !== $category->slug) {
            $validated['slug'] = CmsSlug::assertAvailable(CmsBlogCategory::class, $validated['slug'], $category->id);
        }

        $category->fill($validated)->save();

        return $category;
    }

    /**
     * Posts of a deleted category become uncategorised.
     */
    public function deleteCategory(CmsBlogCategory $category): void
    {
        $category->delete();
    }

    /**
     * @param  array{status?: string, category_id?: int, tag?: string, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, CmsBlogPost>
     */
    public function listPosts(array $filters, bool $publishedOnly = false): LengthAwarePaginator
    {
        return CmsBlogPost::query()
            ->with(['category', 'tags'])
            ->when($publishedOnly, static fn ($q) => $q->where('status', CmsPage::PUBLISHED))
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category_id'] ?? null, static fn ($q, $v) => $q->where('cms_blog_category_id', $v))
            ->when($filters['tag'] ?? null, static fn ($q, $v) => $q->whereHas('tags', static fn ($t) => $t->where('slug', $v)))
            ->when($filters['search'] ?? null, static fn ($q, $v) => $q->where('title', 'like', '%'.$v.'%'))
            ->orderByDesc($publishedOnly ? 'published_at' : 'id')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function getPostBySlug(string $slug, bool $publishedOnly): ?CmsBlogPost
    {
        return CmsBlogPost::query()->with(['category', 'tags'])->where('slug', $slug)
            ->when($publishedOnly, static fn ($q) => $q->where('status', CmsPage::PUBLISHED))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPost(array $data, Model $author): CmsBlogPost
    {
        $validated = $this->validatePost($data, true);

        return DB::connection(CmsScope::current())->transaction(function () use ($validated, $author): CmsBlogPost {
            $post = new CmsBlogPost(Arr::except($validated, ['tags', 'slug']));
            $post->slug = isset($validated['slug']) ? CmsSlug::assertAvailable(CmsBlogPost::class, $validated['slug']) : CmsSlug::unique(CmsBlogPost::class, $validated['title']);
            $post->forceFill([
                'author_id' => $author->getKey(),
                'author_name' => (string) ($author->getAttribute('name') ?? ''),
                'status' => CmsPage::DRAFT,
            ])->save();

            if (array_key_exists('tags', $validated)) {
                $this->syncTags($post, (array) $validated['tags']);
            }

            return $post->load(['category', 'tags']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePost(CmsBlogPost $post, array $data): CmsBlogPost
    {
        $validated = $this->validatePost($data, false);

        if (isset($validated['slug']) && $validated['slug'] !== $post->slug) {
            $validated['slug'] = CmsSlug::assertAvailable(CmsBlogPost::class, $validated['slug'], $post->id);
        }

        DB::connection(CmsScope::current())->transaction(function () use ($post, $validated): void {
            $post->fill(Arr::except($validated, ['tags']))->save();

            if (array_key_exists('tags', $validated)) {
                $this->syncTags($post, (array) $validated['tags']);
            }
        });

        if ($post->status === CmsPage::PUBLISHED) {
            SitemapTrigger::requested();
        }

        return $post->load(['category', 'tags']);
    }

    public function deletePost(CmsBlogPost $post): void
    {
        $wasPublished = $post->status === CmsPage::PUBLISHED;
        $post->delete();

        if ($wasPublished) {
            SitemapTrigger::requested();
        }
    }

    public function publishPost(CmsBlogPost $post): CmsBlogPost
    {
        $post->forceFill(['status' => CmsPage::PUBLISHED, 'published_at' => $post->published_at ?? now()])->save();
        SitemapTrigger::requested();

        return $post;
    }

    public function unpublishPost(CmsBlogPost $post): CmsBlogPost
    {
        $post->forceFill(['status' => CmsPage::DRAFT])->save();
        SitemapTrigger::requested();

        return $post;
    }

    /**
     * Tags by name, created as needed; unused tags are kept until deleted.
     *
     * @param  list<string>  $names
     */
    public function syncTags(CmsBlogPost $post, array $names): void
    {
        $ids = [];

        foreach (array_unique(array_filter(array_map(static fn ($n): string => trim((string) $n), $names))) as $name) {
            $slug = Str::slug($name);

            if ($slug === '') {
                continue;
            }

            $ids[] = CmsTag::query()->firstOrCreate(['slug' => $slug], ['name' => mb_substr($name, 0, 60)])->id;
        }

        $post->tags()->sync($ids);
    }

    /**
     * @return Collection<int, CmsTag>
     */
    public function listTags(): Collection
    {
        return CmsTag::query()->orderBy('name')->get();
    }

    public function deleteTag(CmsTag $tag): void
    {
        $tag->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatePost(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return validator($data, [
            'cms_blog_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists(CmsScope::current().'.cms_blog_categories', 'id')],
            'title' => [$req, 'string', 'max:200'],
            'slug' => ['sometimes', 'string', 'max:190'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => [$req, 'string', 'max:200000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'canonical_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateTaxonomy(array $data, bool $creating): array
    {
        return validator($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:190'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }
}
