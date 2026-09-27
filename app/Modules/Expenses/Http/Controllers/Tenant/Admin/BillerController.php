<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\ExpensePresenter;
use App\Modules\Expenses\Models\Biller;
use App\Modules\Expenses\Services\BillerService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Billers (spec §57.8, Expenses module).
 */
final class BillerController extends Controller
{
    public function __construct(
        private readonly BillerService $billers,
        private readonly ExpensePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['sometimes', Rule::in(Biller::CATEGORIES)],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->billers->listBillers($filters)->through(fn (Biller $b): array => $this->presenter->biller($b)));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->biller($this->billers->createBiller($request->all())), 'Biller created');
    }

    public function update(Request $request, Biller $biller): JsonResponse
    {
        return APIResponse::success($this->presenter->biller($this->billers->updateBiller($biller, $request->all())), 'Biller updated');
    }

    public function deactivate(Biller $biller): JsonResponse
    {
        return APIResponse::success($this->presenter->biller($this->billers->deactivateBiller($biller)), 'Biller deactivated');
    }
}
