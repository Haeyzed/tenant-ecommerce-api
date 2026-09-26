<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use Closure;

/**
 * Resolves tenant menu links to catalogue records (category, brand,
 * product; spec §24.3). The catalogue module registers a resolver for each
 * type when it is built; until then those link types are not accepted.
 * A resolver returns the public path of a live record, or null when the
 * record is missing, unpublished or inactive (the item is then omitted).
 */
final class CmsLinkResolver
{
    public const array CATALOGUE_TYPES = ['category', 'brand', 'product'];

    /** @var array<string, Closure(int): ?string> */
    private array $resolvers = [];

    /**
     * @param  Closure(int): ?string  $resolver
     */
    public function register(string $linkType, Closure $resolver): void
    {
        $this->resolvers[$linkType] = $resolver;
    }

    public function supports(string $linkType): bool
    {
        return isset($this->resolvers[$linkType]);
    }

    public function path(string $linkType, int $id): ?string
    {
        return isset($this->resolvers[$linkType]) ? ($this->resolvers[$linkType])($id) : null;
    }
}
