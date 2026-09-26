<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Services\BrandService;
use App\Modules\Cms\Support\CmsMedia;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Brands (spec §27.5).
 */
final class BrandController extends Controller
{
    public function __construct(
        private readonly BrandService $brands,
        private readonly CatalogPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->brands->listBrands()->map(fn (Brand $b): array => $this->presenter->brand($b))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->brand($this->brands->createBrand($request->all())), 'Brand created');
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        return APIResponse::success($this->presenter->brand($this->brands->updateBrand($brand, $request->all())), 'Brand updated');
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->brands->deleteBrand($brand);

        return APIResponse::noContent('Brand deleted');
    }

    /**
     * Multipart "image"; collection logo (default) or og_image.
     */
    public function image(Request $request, Brand $brand, CmsMedia $media): JsonResponse
    {
        $validated = $request->validate(['image' => ['required', 'file'], 'collection' => ['sometimes', Rule::in(['logo', 'og_image'])]]);
        /** @var UploadedFile $upload */
        $upload = $request->file('image');
        $stored = $media->store($brand, $validated['collection'] ?? 'logo', $upload);

        return APIResponse::created(['id' => $stored->id, 'collection' => $stored->collection_name, 'url' => $stored->getUrl()], 'Image uploaded');
    }
}
