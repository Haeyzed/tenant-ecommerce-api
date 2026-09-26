<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http;

use App\Modules\Cms\Models\CmsBanner;
use App\Modules\Cms\Models\CmsBlogCategory;
use App\Modules\Cms\Models\CmsBlogPost;
use App\Modules\Cms\Models\CmsFaq;
use App\Modules\Cms\Models\CmsFaqCategory;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsMenuItem;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Models\CmsTag;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Models\ContactSubmission;
use App\Modules\Cms\Support\CmsPaths;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The JSON shape of CMS records, shared by admin and public routes of both
 * scopes. Public shapes omit editorial fields (status, system keys).
 */
final class CmsPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function page(CmsPage $page, bool $public, bool $withSections = true): array
    {
        $sections = $withSections
            ? $page->sections->filter(static fn (CmsPageSection $s): bool => ! $public || $s->is_active)->map(static fn (CmsPageSection $s): array => self::section($s, $page, $public))->values()->all()
            : null;

        return array_filter([
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'path' => CmsPaths::page($page),
            'system_key' => $public ? null : $page->system_key,
            'is_homepage' => $page->is_homepage,
            'status' => $public ? null : $page->status,
            'published_at' => $page->published_at?->toIso8601String(),
            'body' => $page->body,
            'seo' => [
                'meta_title' => $page->meta_title ?? $page->title,
                'meta_description' => $page->meta_description,
                'canonical_url' => $page->canonical_url,
                'robots' => $page->robots,
                'og_image_url' => $page->getFirstMediaUrl('og_image') ?: null,
            ],
            'sections' => $sections,
            'updated_at' => $public ? null : $page->updated_at->toIso8601String(),
        ], static fn ($v, string $k): bool => $v !== null || in_array($k, ['body', 'published_at'], true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, mixed>
     */
    public static function section(CmsPageSection $section, CmsPage $page, bool $public): array
    {
        $settings = $section->settings;

        // A section's image is a media row of its page; expose its URL.
        if (isset($settings['media_id'])) {
            $media = $page->media->firstWhere('id', (int) $settings['media_id']);
            $settings['media_url'] = $media instanceof Media ? $media->getUrl() : null;
        }

        return array_filter([
            'id' => $public ? null : $section->id,
            'section_type' => $section->section_type,
            'settings' => $settings,
            'is_active' => $public ? null : $section->is_active,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function menu(CmsMenu $menu): array
    {
        $items = $menu->items;
        $tree = static function (?int $parent) use (&$tree, $items): array {
            return $items->where('parent_id', $parent)->map(static fn (CmsMenuItem $i): array => [
                'id' => $i->id,
                'label' => $i->label,
                'link_type' => $i->link_type,
                'linkable_id' => $i->linkable_id,
                'url' => $i->url,
                'open_in_new_tab' => $i->open_in_new_tab,
                'is_active' => $i->is_active,
                'children' => $tree($i->id),
            ])->values()->all();
        };

        return ['id' => $menu->id, 'key' => $menu->key, 'name' => $menu->name, 'items' => $tree(null)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function banner(CmsBanner $banner, bool $public): array
    {
        $data = [
            'id' => $banner->id,
            'kind' => $banner->kind,
            'title' => $banner->title,
            'body' => $banner->body,
            'link_url' => $banner->link_url,
            'link_label' => $banner->link_label,
            'position' => $banner->position,
            'background_color' => $banner->background_color,
            'text_color' => $banner->text_color,
            'is_dismissible' => $banner->is_dismissible,
            'image_url' => $banner->getFirstMediaUrl('image') ?: null,
            'sort_order' => $banner->sort_order,
        ];

        return $public ? $data : [
            ...$data,
            'starts_at' => $banner->starts_at?->toIso8601String(),
            'ends_at' => $banner->ends_at?->toIso8601String(),
            'is_active' => $banner->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function post(CmsBlogPost $post, bool $public, bool $withBody = true): array
    {
        return array_filter([
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'path' => CmsPaths::post($post),
            'excerpt' => $post->excerpt,
            'body' => $withBody ? $post->body : null,
            'author_name' => $post->author_name,
            'status' => $public ? null : $post->status,
            'published_at' => $post->published_at?->toIso8601String(),
            'category' => $post->category === null ? null : ['id' => $post->category->id, 'name' => $post->category->name, 'slug' => $post->category->slug],
            'tags' => $post->tags->map(static fn (CmsTag $t): array => ['name' => $t->name, 'slug' => $t->slug])->values()->all(),
            'cover_image_url' => $post->getFirstMediaUrl('cover_image') ?: null,
            'seo' => ['meta_title' => $post->meta_title ?? $post->title, 'meta_description' => $post->meta_description ?? $post->excerpt, 'canonical_url' => $post->canonical_url],
        ], static fn ($v, string $k): bool => $v !== null || in_array($k, ['excerpt', 'category', 'cover_image_url', 'published_at'], true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(CmsBlogCategory|CmsFaqCategory $category, bool $public): array
    {
        return array_filter([
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'sort_order' => $category->sort_order,
            'is_active' => $public ? null : $category->is_active,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    public static function tag(CmsTag $tag): array
    {
        return ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug];
    }

    /**
     * @return array<string, mixed>
     */
    public static function faq(CmsFaq $faq, bool $public): array
    {
        return array_filter([
            'id' => $faq->id,
            'category_id' => $faq->cms_faq_category_id,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'sort_order' => $faq->sort_order,
            'is_active' => $public ? null : $faq->is_active,
        ], static fn ($v, string $k): bool => $v !== null || $k === 'category_id', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, mixed>
     */
    public static function testimonial(CmsTestimonial $testimonial, bool $public): array
    {
        return array_filter([
            'id' => $testimonial->id,
            'customer_name' => $testimonial->customer_name,
            'customer_title' => $testimonial->customer_title,
            'quote' => $testimonial->quote,
            'rating' => $testimonial->rating,
            'is_featured' => $testimonial->is_featured,
            'photo_url' => $testimonial->getFirstMediaUrl('photo') ?: null,
            'sort_order' => $testimonial->sort_order,
            'is_active' => $public ? null : $testimonial->is_active,
        ], static fn ($v, string $k): bool => $v !== null || in_array($k, ['customer_title', 'rating', 'photo_url'], true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, mixed>
     */
    public static function submission(ContactSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'name' => $submission->name,
            'email' => $submission->email,
            'phone' => $submission->phone,
            'subject' => $submission->subject,
            'message' => $submission->message,
            'status' => $submission->status,
            'customer_id' => $submission->customer_id,
            'handled_by_id' => $submission->handled_by_id,
            'created_at' => $submission->created_at->toIso8601String(),
        ];
    }
}
