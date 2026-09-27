<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Services\InvoiceTemplateService;
use App\Modules\Orders\Http\OrderAccess;
use App\Modules\Orders\Models\Order;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shopper's invoice (spec §39.7, §43.2): own or guest-token orders,
 * once confirmed, with the default template.
 */
final class OrderDocumentController extends Controller
{
    public function __construct(private readonly InvoiceTemplateService $invoices) {}

    public function invoice(Request $request, Order $order): Response
    {
        OrderAccess::assertCanView($request, $order);

        if ($order->confirmed_at === null) {
            throw ApiException::unprocessable('invoice_not_ready', 'The invoice is available once the order is confirmed.');
        }

        return $this->invoices->renderInvoice($order)->toResponse();
    }
}
