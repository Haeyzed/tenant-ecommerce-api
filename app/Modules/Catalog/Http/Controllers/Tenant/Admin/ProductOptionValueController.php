<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Services\CatalogReferenceService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Values of a product option (spec §28.6).
 */
final class ProductOptionValueController extends Controller
{
    public function __construct(private readonly CatalogReferenceService $references) {}

    public function store(Request $request, ProductOption $option): JsonResponse
    {
        $value = $this->references->addValue($option, (string) $request->input('value', ''));

        return APIResponse::created(['id' => $value->id, 'value' => $value->value, 'sort_order' => $value->sort_order], 'Value added');
    }

    public function destroy(ProductOption $option, int $value): JsonResponse
    {
        $this->references->removeValue(ProductOptionValue::query()->where('product_option_id', $option->id)->whereKey($value)->firstOrFail());

        return APIResponse::noContent('Value removed');
    }
}
