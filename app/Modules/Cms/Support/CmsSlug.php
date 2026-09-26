<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\CmsModel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unique, URL-safe slugs for pages, posts and taxonomies.
 */
final class CmsSlug
{
    /**
     * @param  class-string<CmsModel>  $model
     */
    public static function unique(string $model, string $source, ?int $ignoreId = null, string $field = 'slug'): string
    {
        $base = Str::slug($source);

        if ($base === '') {
            throw ValidationException::withMessages([$field => ['Use letters or digits in the slug.']]);
        }

        $base = mb_substr($base, 0, 180);
        $slug = $base;
        $n = 2;

        while ($model::query()->where('slug', $slug)->when($ignoreId !== null, static fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * An explicitly chosen slug must already be valid and free.
     *
     * @param  class-string<CmsModel>  $model
     */
    public static function assertAvailable(string $model, string $slug, ?int $ignoreId = null): string
    {
        $normalized = Str::slug($slug);

        if ($normalized === '' || $normalized !== $slug) {
            throw ValidationException::withMessages(['slug' => ['Use lower-case letters, digits and hyphens.']]);
        }

        if ($model::query()->where('slug', $slug)->when($ignoreId !== null, static fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            throw ValidationException::withMessages(['slug' => ['This slug is taken.']]);
        }

        return $slug;
    }
}
