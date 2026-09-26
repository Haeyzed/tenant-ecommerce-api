<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\CmsBanner;
use App\Modules\Cms\Support\CmsScope;
use App\Modules\Settings\Services\StorefrontConfigService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Image banners and the announcement bar (spec §24.5).
 */
final readonly class CmsBannerService
{
    /**
     * @param  array{kind?: string, position?: string}  $filters
     * @return Collection<int, CmsBanner>
     */
    public function listBanners(array $filters = []): Collection
    {
        return CmsBanner::query()
            ->when($filters['kind'] ?? null, static fn ($q, $v) => $q->where('kind', $v))
            ->when($filters['position'] ?? null, static fn ($q, $v) => $q->where('position', $v))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBanner(array $data): CmsBanner
    {
        $banner = new CmsBanner($this->validate($data, null));
        $banner->save();
        $this->changed();

        return $banner;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBanner(CmsBanner $banner, array $data): CmsBanner
    {
        $banner->fill($this->validate($data, $banner))->save();
        $this->changed();

        return $banner;
    }

    public function deleteBanner(CmsBanner $banner): void
    {
        $banner->delete();
        $this->changed();
    }

    /**
     * Live image banners, optionally of one position. An image banner
     * without its image is not live.
     *
     * @return Collection<int, CmsBanner>
     */
    public function getLiveBanners(?string $position): Collection
    {
        return CmsBanner::query()
            ->live()
            ->where('kind', CmsBanner::IMAGE)
            ->when($position !== null, static fn ($q) => $q->where('position', $position))
            ->whereHas('media', static fn ($q) => $q->where('collection_name', 'image'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The live announcements, at most three, in order (§24.5).
     *
     * @return Collection<int, CmsBanner>
     */
    public function getAnnouncementBar(): Collection
    {
        return CmsBanner::query()
            ->live()
            ->where('kind', CmsBanner::ANNOUNCEMENT)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit((int) config('cms.announcement_bar_max', 3))
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?CmsBanner $existing): array
    {
        $kind = (string) ($data['kind'] ?? $existing?->kind ?? '');
        $creating = $existing === null;

        $validated = validator($data, [
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in([CmsBanner::IMAGE, CmsBanner::ANNOUNCEMENT])],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'body' => [$kind === CmsBanner::ANNOUNCEMENT && $creating ? 'required' : 'sometimes', 'nullable', 'string', 'max:200'],
            'link_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:/^(https?:\/\/|\/)/i'],
            'link_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'position' => [$kind === CmsBanner::IMAGE && $creating ? 'required' : 'sometimes', 'nullable', Rule::in((array) config('cms.banner_positions'))],
            'background_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'text_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_dismissible' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ])->validate();

        if ($kind === CmsBanner::ANNOUNCEMENT) {
            unset($validated['position']);
        } else {
            foreach (['background_color', 'text_color', 'is_dismissible', 'body'] as $field) {
                if (array_key_exists($field, $validated) && $validated[$field] !== null && $field !== 'is_dismissible') {
                    throw ValidationException::withMessages([$field => ['Only announcements have this field.']]);
                }
            }
        }

        return $validated;
    }

    /**
     * The storefront config carries the announcement bar (§13.5).
     */
    private function changed(): void
    {
        if (CmsScope::isTenant()) {
            StorefrontConfigService::flush();
        }
    }
}
