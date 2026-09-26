<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a tenant export through its temporary signed link (spec §6.7).
 */
final class TenantExportDownloadController extends Controller
{
    public function __invoke(string $file): StreamedResponse
    {
        $path = 'tenant-exports/'.basename($file);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }
}
