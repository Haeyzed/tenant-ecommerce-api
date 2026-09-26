<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Models\CmsMenuItem;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Support\CmsLinkResolver;
use App\Modules\Cms\Support\CmsPaths;
use App\Modules\Cms\Support\CmsScope;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Menus and their item trees (spec §24.3).
 */
final readonly class CmsMenuService
{
    public function __construct(
        private CmsLinkResolver $links,
        private FeatureAccessService $features,
    ) {}

    /**
     * @return Collection<int, CmsMenu>
     */
    public function listMenus(): Collection
    {
        return CmsMenu::query()->with('items')->orderBy('key')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMenu(array $data): CmsMenu
    {
        $validated = validator($data, [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,63}$/', Rule::unique(CmsScope::current().'.cms_menus', 'key')],
            'name' => ['required', 'string', 'max:120'],
        ])->validate();

        /** @var CmsMenu $menu */
        $menu = CmsMenu::query()->create($validated);

        return $menu;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMenu(CmsMenu $menu, array $data): CmsMenu
    {
        $menu->fill(validator($data, ['name' => ['required', 'string', 'max:120']])->validate())->save();

        return $menu;
    }

    public function deleteMenu(CmsMenu $menu): void
    {
        $menu->delete();
    }

    /**
     * Replaces the menu's items with the given ordered tree (maximum depth
     * 2), validating every link for the current scope.
     *
     * @param  list<array<string, mixed>>  $tree
     */
    public function syncItems(CmsMenu $menu, array $tree): CmsMenu
    {
        $rows = [];
        $this->flatten($tree, 1, null, $rows, 'items');

        DB::connection($menu->getConnectionName())->transaction(static function () use ($menu, $rows): void {
            CmsMenuItem::query()->where('cms_menu_id', $menu->id)->whereNotNull('parent_id')->delete();
            CmsMenuItem::query()->where('cms_menu_id', $menu->id)->delete();

            $ids = [];

            foreach ($rows as $row) {
                $parent = $row['parent_ref'];
                unset($row['parent_ref'], $row['ref']);

                /** @var CmsMenuItem $item */
                $item = CmsMenuItem::query()->create([...$row, 'cms_menu_id' => $menu->id, 'parent_id' => $parent === null ? null : $ids[$parent]]);
                $ids[] = $item->id;
            }
        });

        return $menu->load('items');
    }

    /**
     * The public menu: active items resolved to paths; items whose target
     * is unpublished, inactive or deleted are omitted.
     *
     * @return array{key: string, name: string, items: list<array<string, mixed>>}|null
     */
    public function getResolvedMenu(string $key): ?array
    {
        $menu = CmsMenu::query()->with('items')->where('key', $key)->first();

        if ($menu === null) {
            return null;
        }

        $items = $menu->items->where('is_active', true);
        $pages = CmsPage::query()->whereIn('id', $items->where('link_type', 'page')->pluck('linkable_id')->filter()->all())
            ->where('status', CmsPage::PUBLISHED)->get()->keyBy('id');
        $blogOn = $this->blogAvailable();

        $resolve = function (CmsMenuItem $item) use ($pages, $blogOn): ?string {
            return match ($item->link_type) {
                'url' => $item->url,
                'page' => ($page = $pages->get($item->linkable_id)) !== null ? CmsPaths::page($page) : null,
                'blog' => $blogOn ? CmsPaths::blog() : null,
                default => $item->linkable_id === null ? null : $this->links->path($item->link_type, $item->linkable_id),
            };
        };

        $build = function (?int $parentId) use (&$build, $items, $resolve): array {
            $out = [];

            foreach ($items->where('parent_id', $parentId) as $item) {
                $url = $resolve($item);

                if ($url === null) {
                    continue;
                }

                $out[] = [
                    'label' => $item->label,
                    'url' => $url,
                    'link_type' => $item->link_type,
                    'open_in_new_tab' => $item->open_in_new_tab,
                    'children' => $build($item->id),
                ];
            }

            return $out;
        };

        return ['key' => $menu->key, 'name' => $menu->name, 'items' => $build(null)];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $rows
     */
    private function flatten(array $nodes, int $depth, ?int $parentRef, array &$rows, string $path): void
    {
        if ($depth > (int) config('cms.menu_max_depth', 2)) {
            throw ValidationException::withMessages([$path => ['Menus are at most two levels deep.']]);
        }

        $allowed = array_keys(array_filter((array) config('cms.link_types'), static fn (array $scopes): bool => in_array(CmsScope::current(), $scopes, true)));

        foreach (array_values($nodes) as $i => $node) {
            $at = "{$path}.{$i}";
            $validated = validator((array) $node, [
                'label' => ['required', 'string', 'max:120'],
                'link_type' => ['required', Rule::in($allowed)],
                'linkable_id' => ['nullable', 'integer'],
                'url' => ['nullable', 'string', 'max:2048'],
                'open_in_new_tab' => ['sometimes', 'boolean'],
                'is_active' => ['sometimes', 'boolean'],
                'children' => ['sometimes', 'array'],
            ])->validate();

            $this->assertTarget($validated, $at);

            $rows[] = [
                'ref' => count($rows),
                'parent_ref' => $parentRef,
                'label' => $validated['label'],
                'link_type' => $validated['link_type'],
                'linkable_id' => $validated['link_type'] === 'url' || $validated['link_type'] === 'blog' ? null : (int) $validated['linkable_id'],
                'url' => $validated['link_type'] === 'url' ? $validated['url'] : null,
                'open_in_new_tab' => (bool) ($validated['open_in_new_tab'] ?? false),
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'sort_order' => $i,
            ];

            if (! empty($validated['children'])) {
                $this->flatten($validated['children'], $depth + 1, count($rows) - 1, $rows, $at.'.children');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function assertTarget(array $item, string $at): void
    {
        $type = $item['link_type'];

        if ($type === 'url') {
            if (preg_match('/^(https?:\/\/|mailto:|tel:|\/)/i', (string) ($item['url'] ?? '')) !== 1) {
                throw ValidationException::withMessages([$at.'.url' => ['Use an http, https, mailto or tel link, or a path.']]);
            }

            return;
        }

        if ($type === 'blog') {
            return;
        }

        if (empty($item['linkable_id'])) {
            throw ValidationException::withMessages([$at.'.linkable_id' => ['Choose what this item links to.']]);
        }

        if ($type === 'page') {
            if (! CmsPage::query()->whereKey((int) $item['linkable_id'])->exists()) {
                throw ValidationException::withMessages([$at.'.linkable_id' => ['This page does not exist.']]);
            }

            return;
        }

        if (! $this->links->supports($type)) {
            throw ApiException::unprocessable('link_type_not_available', "Links to a {$type} are available once the catalogue is set up.", ['link_type' => $type]);
        }

        if ($this->links->path($type, (int) $item['linkable_id']) === null) {
            throw ValidationException::withMessages([$at.'.linkable_id' => ["This {$type} does not exist."]]);
        }
    }

    /**
     * The blog link is omitted in the tenant scope while content_marketing
     * is not enabled; the landlord blog is always available.
     */
    private function blogAvailable(): bool
    {
        $tenant = tenant();

        return ! $tenant instanceof Tenant || $this->features->tenantCanAccess($tenant, 'content_marketing');
    }
}
