<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Product images (spec §30.1).
 */
final class ProductMediaController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate(['image' => ['required', 'file'], 'collection' => ['sometimes', Rule::in(['gallery', 'featured', 'og_image'])]]);
        /** @var UploadedFile $upload */
        $upload = $request->file('image');
        $media = $this->products->attachMedia($product, $upload, $validated['collection'] ?? 'gallery');

        return APIResponse::created(['id' => $media->id, 'collection' => $media->collection_name, 'url' => $media->getUrl()], 'Image uploaded');
    }

    public function destroy(Product $product, int $media): JsonResponse
    {
        $this->products->removeMedia($product, $media);

        return APIResponse::noContent('Image removed');
    }

    public function reorder(Request $request, Product $product): JsonResponse
    {
        $ids = $request->validate(['ordered_ids' => ['required', 'array', 'min:1'], 'ordered_ids.*' => ['integer']])['ordered_ids'];
        $this->products->reorderMedia($product, $ids);

        return APIResponse::success(null, 'Images reordered');
    }

    public function setFeatured(Product $product, int $media): JsonResponse
    {
        $featured = $this->products->setFeaturedImage($product, $media);

        return APIResponse::success(['id' => $featured->id, 'url' => $featured->getUrl()], 'Featured image set');
    }
}
