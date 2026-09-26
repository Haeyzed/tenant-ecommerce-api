<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageSection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the scope's CMS structure from config cms.defaults (spec §24.6):
 * system pages and empty menus, never legal or marketing text. Insert-only
 * by system_key and menu key, so a tenant's edits are never overwritten.
 */
final class CmsDefaults
{
    public static function seed(): int
    {
        $defaults = (array) config('cms.defaults.'.CmsScope::current(), []);
        $created = 0;

        DB::connection(CmsScope::current())->transaction(static function () use ($defaults, &$created): void {
            foreach ((array) ($defaults['pages'] ?? []) as $definition) {
                if (CmsPage::query()->where('system_key', $definition['system_key'])->exists()) {
                    continue;
                }

                $page = new CmsPage([
                    'title' => $definition['title'],
                    'slug' => CmsSlug::unique(CmsPage::class, $definition['slug']),
                ]);
                $published = ($definition['status'] ?? CmsPage::DRAFT) === CmsPage::PUBLISHED;
                $page->forceFill([
                    'system_key' => $definition['system_key'],
                    'status' => $published ? CmsPage::PUBLISHED : CmsPage::DRAFT,
                    'published_at' => $published ? now() : null,
                    'is_homepage' => (bool) ($definition['is_homepage'] ?? false) && ! CmsPage::query()->where('is_homepage', true)->exists(),
                ])->save();

                foreach (array_values((array) ($definition['sections'] ?? [])) as $i => $section) {
                    CmsPageSection::query()->create([
                        'cms_page_id' => $page->id,
                        'section_type' => $section['section_type'],
                        'settings' => $section['settings'],
                        'is_active' => true,
                        'sort_order' => $i,
                    ]);
                }

                $created++;
            }

            foreach ((array) ($defaults['menus'] ?? []) as $menu) {
                if (! CmsMenu::query()->where('key', $menu['key'])->exists()) {
                    CmsMenu::query()->create(['key' => $menu['key'], 'name' => $menu['name']]);
                    $created++;
                }
            }
        });

        return $created;
    }
}
