<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\DigitalProductFile;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Downloadable files of a digital product (spec §28.6), stored privately.
 */
final class DigitalFileController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function store(Request $request, Product $product): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        /** @var UploadedFile $upload */
        $upload = $request->file('file');
        $file = $this->products->attachDigitalFile($product, $upload, $request->only(['download_limit', 'expires_after_days']));

        return APIResponse::created([
            'id' => $file->id,
            'name' => $file->getFirstMedia('file')?->name,
            'download_limit' => $file->download_limit,
            'expires_after_days' => $file->expires_after_days,
        ], 'File uploaded');
    }

    public function destroy(Product $product, int $file): JsonResponse
    {
        $this->products->removeDigitalFile(DigitalProductFile::query()->where('product_id', $product->id)->whereKey($file)->firstOrFail());

        return APIResponse::noContent('File removed');
    }
}
