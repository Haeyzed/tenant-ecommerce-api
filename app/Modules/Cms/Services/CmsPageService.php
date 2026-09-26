<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Support\CmsSlug;
use App\Modules\Cms\Support\SitemapTrigger;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CMS pages in the current scope (spec §24.2, §24.9).
 */
final readonly class CmsPageService
{
    /**
     * @param  array{status?: string, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, CmsPage>
     */
    public function listPages(array $filters): LengthAwarePaginator
    {
        return CmsPage::query()
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, static fn ($q, $v) => $q->where(static fn ($q) => $q->where('title', 'like', '%'.$v.'%')->orWhere('slug', 'like', '%'.$v.'%')))
            ->orderByDesc('is_homepage')
            ->orderBy('title')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPage(array $data): CmsPage
    {
        $validated = $this->validate($data, true);

        $page = new CmsPage($validated);
        $page->slug = isset($validated['slug']) ? CmsSlug::assertAvailable(CmsPage::class, $validated['slug']) : CmsSlug::unique(CmsPage::class, $validated['title']);
        $page->status = CmsPage::DRAFT;
        $page->save();

        return $page;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePage(CmsPage $page, array $data): CmsPage
    {
        $validated = $this->validate($data, false);

        if (isset($validated['slug']) && $validated['slug'] !== $page->slug) {
            $validated['slug'] = CmsSlug::assertAvailable(CmsPage::class, $validated['slug'], $page->id);
        }

        $page->fill($validated)->save();

        if ($page->isPublished()) {
            SitemapTrigger::requested();
        }

        return $page;
    }

    /**
     * System pages are linked to by key from checkout, registration and
     * emails; they can be edited or unpublished but never deleted.
     */
    public function deletePage(CmsPage $page): void
    {
        if ($page->system_key !== null) {
            throw ApiException::unprocessable('system_page', 'A system page cannot be deleted. Unpublish it instead.', ['system_key' => $page->system_key]);
        }

        $wasPublished = $page->isPublished();
        $page->delete();

        if ($wasPublished) {
            SitemapTrigger::requested();
        }
    }

    public function publishPage(CmsPage $page): CmsPage
    {
        $page->forceFill(['status' => CmsPage::PUBLISHED, 'published_at' => $page->published_at ?? now()])->save();
        SitemapTrigger::requested();

        return $page;
    }

    public function unpublishPage(CmsPage $page): CmsPage
    {
        $page->forceFill(['status' => CmsPage::DRAFT])->save();
        SitemapTrigger::requested();

        return $page;
    }

    /**
     * At most one homepage; set under a lock so two requests cannot leave
     * two.
     */
    public function setHomepage(CmsPage $page): CmsPage
    {
        DB::connection($page->getConnectionName())->transaction(static function () use ($page): void {
            CmsPage::query()->where('is_homepage', true)->lockForUpdate()->get();
            CmsPage::query()->where('is_homepage', true)->whereKeyNot($page->id)->update(['is_homepage' => false]);
            $page->forceFill(['is_homepage' => true])->save();
        });

        SitemapTrigger::requested();

        return $page;
    }

    public function getBySlug(string $slug, bool $publishedOnly): ?CmsPage
    {
        return CmsPage::query()
            ->where('slug', $slug)
            ->when($publishedOnly, static fn ($q) => $q->where('status', CmsPage::PUBLISHED))
            ->first();
    }

    public function getHomepage(): ?CmsPage
    {
        return CmsPage::query()->where('is_homepage', true)->where('status', CmsPage::PUBLISHED)->first();
    }

    public function getBySystemKey(string $key): ?CmsPage
    {
        return CmsPage::query()->where('system_key', $key)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return validator($data, [
            'title' => [$req, 'string', 'max:200'],
            'slug' => ['sometimes', 'string', 'max:190'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'canonical_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'robots' => ['sometimes', Rule::in(CmsPage::ROBOTS)],
        ])->validate();
    }
}
