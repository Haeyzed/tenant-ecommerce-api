<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Services\CatalogReferenceService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product options (spec §28.6).
 */
final class ProductOptionController extends Controller
{
    public function __construct(
        private readonly CatalogReferenceService $references,
        private readonly CatalogPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->references->listOptions()->map(fn (ProductOption $o): array => $this->presenter->option($o))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->option($this->references->createOption($request->all())), 'Option created');
    }

    public function update(Request $request, ProductOption $option): JsonResponse
    {
        return APIResponse::success($this->presenter->option($this->references->updateOption($option, $request->all())), 'Option updated');
    }

    public function destroy(ProductOption $option): JsonResponse
    {
        $this->references->deleteOption($option);

        return APIResponse::noContent('Option deleted');
    }
}
