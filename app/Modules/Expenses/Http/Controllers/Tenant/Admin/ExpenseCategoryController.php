<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\ExpensePresenter;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expense categories (spec §57.8).
 */
final class ExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ExpensePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->expenses->listCategories()->map(fn (ExpenseCategory $c): array => $this->presenter->category($c))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->category($this->expenses->createCategory($request->all())), 'Category created');
    }

    public function update(Request $request, ExpenseCategory $category): JsonResponse
    {
        return APIResponse::success($this->presenter->category($this->expenses->updateCategory($category, $request->all())), 'Category updated');
    }
}
