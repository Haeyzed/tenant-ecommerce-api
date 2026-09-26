<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\InvoiceTemplate;
use App\Modules\Documents\Services\InvoiceTemplateService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff order documents (spec §39.7, §43.2, §43.3). Warehouse-scoped staff
 * see orders with a line in their warehouses (§25.3).
 */
final class OrderDocumentController extends Controller
{
    public function __construct(
        private readonly InvoiceTemplateService $documents,
        private readonly WarehouseService $warehouses,
    ) {}

    public function invoice(Request $request, Order $order): Response
    {
        $this->assertVisible($request, $order);
        $templateId = $request->validate(['template_id' => ['sometimes', 'integer', Rule::exists('tenant.invoice_templates', 'id')]])['template_id'] ?? null;

        return $this->documents->renderInvoice($order, $templateId === null ? null : InvoiceTemplate::query()->findOrFail((int) $templateId))->toResponse();
    }

    public function packingSlip(Request $request, Order $order): Response
    {
        $this->assertVisible($request, $order);

        return $this->documents->renderPackingSlip($order)->toResponse();
    }

    private function assertVisible(Request $request, Order $order): void
    {
        /** @var User $actor */
        $actor = $request->user();
        $visible = $this->warehouses->visibleIds($actor);

        if ($visible !== null && ! $order->items()->whereIn('warehouse_id', $visible)->exists()) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);
        }
    }
}
