<?php

declare(strict_types=1);

namespace App\Modules\Tax\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Tax\Models\TaxRate;
use App\Modules\Tax\Services\TaxService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tax rates (spec §35.4). Tax reaches customers only as amounts on carts
 * and orders.
 */
final class TaxRateController extends Controller
{
    public function __construct(private readonly TaxService $tax) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'country_id' => ['sometimes', 'integer'],
            'state_id' => ['sometimes', 'integer'],
            'tax_class' => ['sometimes', Rule::in(TaxRate::CLASSES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->tax->listTaxRates($filters)->map(fn (TaxRate $r): array => $this->present($r))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->present($this->tax->createTaxRate($request->all())), 'Tax rate created');
    }

    public function update(Request $request, TaxRate $rate): JsonResponse
    {
        return APIResponse::success($this->present($this->tax->updateTaxRate($rate, $request->all())), 'Tax rate updated');
    }

    public function destroy(TaxRate $rate): JsonResponse
    {
        $this->tax->deleteTaxRate($rate);

        return APIResponse::noContent('Tax rate deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TaxRate $rate): array
    {
        return [
            'id' => $rate->id,
            'name' => $rate->name,
            'country_id' => $rate->country_id,
            'state_id' => $rate->state_id,
            'tax_class' => $rate->tax_class ?? 'standard',
            'rate_percentage' => (string) $rate->rate_percentage,
            'is_active' => (bool) ($rate->is_active ?? true),
        ];
    }
}
