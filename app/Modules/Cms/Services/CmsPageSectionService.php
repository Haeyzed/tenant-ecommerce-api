<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsPageSection;
use App\Modules\Cms\Support\CmsScope;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A page's ordered sections (spec §24.2): replaced as a whole, in one
 * transaction, each validated against its type's scope and schema.
 */
final readonly class CmsPageSectionService
{
    /**
     * @param  list<array{section_type?: string, settings?: array<string, mixed>, is_active?: bool}>  $sections
     */
    public function syncSections(CmsPage $page, array $sections): CmsPage
    {
        $types = (array) config('cms.section_types');
        $scope = CmsScope::current();
        $clean = [];
        $errors = [];

        foreach (array_values($sections) as $i => $section) {
            $type = (string) ($section['section_type'] ?? '');

            if (! isset($types[$type]) || ! in_array($scope, $types[$type]['scopes'], true)) {
                throw ApiException::unprocessable('section_type_not_allowed', "Section type [{$type}] is not available here.", ['index' => $i, 'section_type' => $type]);
            }

            $settings = (array) ($section['settings'] ?? []);
            $validator = Validator::make($settings, (array) $types[$type]['rules']);

            if ($validator->fails()) {
                foreach ($validator->errors()->toArray() as $field => $messages) {
                    $errors["sections.{$i}.settings.{$field}"] = $messages;
                }

                continue;
            }

            $settings = $validator->validated();

            if (isset($settings['media_id']) && ! $page->media()->whereKey((int) $settings['media_id'])->exists()) {
                $errors["sections.{$i}.settings.media_id"] = ['Upload the image to this page first.'];

                continue;
            }

            $clean[] = [
                'section_type' => $type,
                'settings' => $settings,
                'is_active' => (bool) ($section['is_active'] ?? true),
                'sort_order' => $i,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::connection($page->getConnectionName())->transaction(static function () use ($page, $clean): void {
            CmsPageSection::query()->where('cms_page_id', $page->id)->delete();

            foreach ($clean as $row) {
                CmsPageSection::query()->create(['cms_page_id' => $page->id, ...$row]);
            }

            $page->touch();
        });

        return $page->load('sections');
    }
}
