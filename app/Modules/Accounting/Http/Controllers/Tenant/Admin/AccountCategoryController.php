<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\AccountingPresenter;
use App\Modules\Accounting\Models\AccountCategory;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account categories (spec §57.8).
 */
final class AccountCategoryController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountsService $accounts,
        private readonly AccountingPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->accounts->listCategories()->map(fn (AccountCategory $c): array => $this->presenter->category($c))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->category($this->accounts->createCategory($request->all())), 'Category created');
    }

    public function update(Request $request, AccountCategory $category): JsonResponse
    {
        return APIResponse::success($this->presenter->category($this->accounts->updateCategory($category, $request->all())), 'Category updated');
    }
}
