<?php

declare(strict_types=1);

namespace App\Modules\Exports\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Support\ExportFileResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/exports/{export}/download behind the signed middleware (spec
 * §19.4): the emailed link of a customer's personal-data export.
 */
final class SignedExportDownloadController extends Controller
{
    public function __invoke(int $export, ExportFileResponder $responder): Response
    {
        return $responder->respond(DataExport::query()->findOrFail($export));
    }
}
