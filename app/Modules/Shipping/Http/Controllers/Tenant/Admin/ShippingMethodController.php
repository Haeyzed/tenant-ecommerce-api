<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Services\ShippingMethodService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shipping methods (spec §36.4).
 */
final class ShippingMethodController extends Controller
{
    public function __construct(
        private readonly ShippingMethodService $methods,
        private readonly ShippingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'shipping_zone_id' => ['sometimes', 'integer'],
            'fulfillment_type' => ['sometimes', Rule::in([ShippingMethod::COURIER, ShippingMethod::IN_HOUSE])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->methods->listMethods($filters)->map(fn (ShippingMethod $m): array => $this->presenter->method($m, false))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->method($this->methods->createMethod($request->all()), false), 'Shipping method created');
    }

    public function update(Request $request, ShippingMethod $method): JsonResponse
    {
        return APIResponse::success($this->presenter->method($this->methods->updateMethod($method, $request->all()), false), 'Shipping method updated');
    }

    public function destroy(ShippingMethod $method): JsonResponse
    {
        $this->methods->deleteMethod($method);

        return APIResponse::noContent('Shipping method deleted');
    }
}
