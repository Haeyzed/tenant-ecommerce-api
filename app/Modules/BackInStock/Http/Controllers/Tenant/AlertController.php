<?php

declare(strict_types=1);

namespace App\Modules\BackInStock\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\BackInStock\Services\BackInStockService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /api/products/{product}/back-in-stock-alert (spec §56): no sign-in
 * needed; a signed-in customer's email is used when none is given.
 */
final class AlertController extends Controller
{
    public function __construct(private readonly BackInStockService $alerts) {}

    public function store(Request $request, string $product): JsonResponse
    {
        $customer = $request->user('customer');
        $customer = $customer instanceof Customer ? $customer : null;
        $validated = $request->validate([
            'email' => [$customer === null ? 'required' : 'sometimes', 'email:rfc', 'max:255'],
            'product_variant_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $model = (ctype_digit($product) ? Product::query()->visible()->whereKey((int) $product)->first() : null)
            ?? Product::query()->visible()->where('slug', $product)->first()
            ?? throw new NotFoundHttpException('Not found.');
        $variant = isset($validated['product_variant_id'])
            ? ProductVariant::query()->where('product_id', $model->id)->find((int) $validated['product_variant_id']) ?? throw new NotFoundHttpException('Not found.')
            : null;

        $row = $this->alerts->subscribe($model, (string) ($validated['email'] ?? $customer?->email), $customer, $variant);

        return APIResponse::created([
            'id' => $row->id,
            'product_id' => $row->product_id,
            'product_variant_id' => $row->product_variant_id,
            'email' => $row->email,
            'created_at' => $row->created_at->toIso8601String(),
        ], 'We will email you when it is back in stock');
    }
}
