<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Http\DocumentPresenter;
use App\Modules\Documents\Models\InvoiceTemplate;
use App\Modules\Documents\Services\InvoiceTemplateService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invoice templates (spec §43.7).
 */
final class InvoiceTemplateController extends Controller
{
    public function __construct(
        private readonly InvoiceTemplateService $templates,
        private readonly DocumentPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->templates->listTemplates()->map(fn (InvoiceTemplate $t): array => $this->presenter->template($t))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $template = $this->templates->createTemplate($request->validate($this->templates->rules(true)));

        return APIResponse::created($this->presenter->template($template->refresh()), 'Template created');
    }

    public function update(Request $request, InvoiceTemplate $template): JsonResponse
    {
        $template = $this->templates->updateTemplate($template, $request->validate($this->templates->rules(false)));

        return APIResponse::success($this->presenter->template($template), 'Template updated');
    }

    public function destroy(InvoiceTemplate $template): JsonResponse
    {
        $this->templates->deleteTemplate($template);

        return APIResponse::success(null, 'Template deleted');
    }

    public function setDefault(InvoiceTemplate $template): JsonResponse
    {
        return APIResponse::success($this->presenter->template($this->templates->setDefault($template)), 'Default template set');
    }
}
