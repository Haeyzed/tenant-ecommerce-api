<?php

declare(strict_types=1);

namespace App\Modules\Exports\Support;

use App\Modules\Exports\Models\DataExport;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Delivers an export file (spec §19.4): a 302 to a 5-minute temporary URL
 * where the disk supports one (object storage); otherwise the file is
 * streamed, because a local temporary URL would not be tenant-scoped.
 */
final class ExportFileResponder
{
    public function respond(DataExport $export): Response
    {
        if (! $export->isDownloadable()) {
            throw new ApiException('export_unavailable', 'This export is not ready or has expired.', 410);
        }

        $media = $export->getFirstMedia('file') ?? throw new ApiException('export_unavailable', 'This export file is no longer available.', 410);
        $disk = Storage::disk($media->disk);

        if ($media->disk !== 'local' && $disk->providesTemporaryUrls()) {
            try {
                return redirect()->away($media->getTemporaryUrl(now()->addMinutes(5)));
            } catch (Throwable) {
                // Fall back to streaming below.
            }
        }

        return $disk->download($media->getPathRelativeToRoot(), $media->file_name);
    }
}
