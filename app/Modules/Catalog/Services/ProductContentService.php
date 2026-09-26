<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\ProductSpecification;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Product relations (§29.2) and specifications (§29.3): the operations of
 * the spec's ProductRelationService and ProductSpecificationService.
 */
final readonly class ProductContentService
{
    public function addRelation(Product $product, Product $related, string $type, int $sortOrder = 0): ProductRelation
    {
        validator(['relation_type' => $type], ['relation_type' => ['required', Rule::in(ProductRelation::TYPES)]])->validate();

        if ($product->id === $related->id) {
            throw ApiException::unprocessable('relation_self', 'A product cannot be related to itself.');
        }

        if (ProductRelation::query()->where('product_id', $product->id)->where('related_product_id', $related->id)->where('relation_type', $type)->exists()) {
            throw ApiException::conflict('relation_exists', 'These products are already related this way.');
        }

        /** @var ProductRelation $relation */
        $relation = ProductRelation::query()->create([
            'product_id' => $product->id,
            'related_product_id' => $related->id,
            'relation_type' => $type,
            'sort_order' => $sortOrder,
        ]);

        return $relation;
    }

    public function removeRelation(ProductRelation $relation): void
    {
        $relation->delete();
    }

    /**
     * Active (visible) related products of one type, in order.
     *
     * @return Collection<int, Product>
     */
    public function getRelated(Product $product, string $type): Collection
    {
        return Product::query()->visible()
            ->select('products.*')
            ->join('product_relations', 'product_relations.related_product_id', '=', 'products.id')
            ->where('product_relations.product_id', $product->id)
            ->where('product_relations.relation_type', $type)
            ->orderBy('product_relations.sort_order')
            ->with(['brand:id,name,slug', 'media'])
            ->get();
    }

    /**
     * Replaces all specifications, in the given order.
     *
     * @param  list<array<string, mixed>>  $specs
     */
    public function setSpecifications(Product $product, array $specs): Collection
    {
        $validated = validator(['specifications' => $specs], [
            'specifications' => ['present', 'array', 'max:200'],
            'specifications.*.spec_group' => ['sometimes', 'nullable', 'string', 'max:120'],
            'specifications.*.spec_key' => ['required', 'string', 'max:120'],
            'specifications.*.spec_value' => ['required', 'string', 'max:255'],
        ])->validate()['specifications'];

        DB::connection('tenant')->transaction(static function () use ($product, $validated): void {
            ProductSpecification::query()->where('product_id', $product->id)->delete();

            foreach (array_values($validated) as $i => $spec) {
                $product->specifications()->create([
                    'spec_group' => $spec['spec_group'] ?? null,
                    'spec_key' => $spec['spec_key'],
                    'spec_value' => $spec['spec_value'],
                    'sort_order' => $i,
                ]);
            }
        });

        return $this->listSpecifications($product);
    }

    /**
     * @return Collection<int, ProductSpecification>
     */
    public function listSpecifications(Product $product): Collection
    {
        return $product->specifications()->get();
    }
}
