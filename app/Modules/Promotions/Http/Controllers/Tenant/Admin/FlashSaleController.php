<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Promotions\Http\PromotionPresenter;
use App\Modules\Promotions\Models\FlashSale;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Flash sales (spec §37.9).
 */
final class FlashSaleController extends Controller
{
    public function __construct(
        private readonly FlashSaleService $sales,
        private readonly PromotionPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(['scheduled', 'running', 'ended'])]]);

        return APIResponse::success($this->sales->listFlashSales($filters)->map(fn (FlashSale $s): array => $this->presenter->flashSale($s))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->flashSale($this->sales->createFlashSale($request->all())), 'Flash sale created');
    }

    public function update(Request $request, FlashSale $sale): JsonResponse
    {
        return APIResponse::success($this->presenter->flashSale($this->sales->updateFlashSale($sale, $request->all())), 'Flash sale updated');
    }

    public function destroy(FlashSale $sale): JsonResponse
    {
        $this->sales->deleteFlashSale($sale);

        return APIResponse::noContent('Flash sale deleted');
    }
}
