<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\ExpensePresenter;
use App\Modules\Expenses\Models\IncomeCategory;
use App\Modules\Expenses\Services\IncomeService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Income categories (spec §57.8).
 */
final class IncomeCategoryController extends Controller
{
    public function __construct(
        private readonly IncomeService $income,
        private readonly ExpensePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->income->listCategories()->map(fn (IncomeCategory $c): array => $this->presenter->category($c))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->category($this->income->createCategory($request->all())), 'Category created');
    }

    public function update(Request $request, IncomeCategory $category): JsonResponse
    {
        return APIResponse::success($this->presenter->category($this->income->updateCategory($category, $request->all())), 'Category updated');
    }
}
