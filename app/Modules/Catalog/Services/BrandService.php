<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Cms\Support\SitemapTrigger;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Brands (spec §27.2). Deleting a brand leaves its products unbranded
 * (products.brand_id is nulled by the foreign key).
 */
final readonly class BrandService
{
    /**
     * @return Collection<int, Brand>
     */
    public function listBrands(): Collection
    {
        return Brand::query()->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBrand(array $data): Brand
    {
        /** @var Brand $brand */
        $brand = Brand::query()->create($this->validate($data, null));
        SitemapTrigger::requested();

        return $brand;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBrand(Brand $brand, array $data): Brand
    {
        $brand->fill($this->validate($data, $brand))->save();
        SitemapTrigger::requested();

        return $brand;
    }

    public function deleteBrand(Brand $brand): void
    {
        $brand->delete();
        SitemapTrigger::requested();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?Brand $existing): array
    {
        return validator($data, [
            'name' => [$existing === null ? 'required' : 'sometimes', 'string', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenant.brands', 'slug')->ignore($existing?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'meta_keywords' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();
    }
}
