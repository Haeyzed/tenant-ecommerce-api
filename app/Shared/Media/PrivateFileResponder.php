<?php

declare(strict_types=1);

namespace App\Shared\Media;

use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Delivers a private file to an already authorised caller (spec §19.3,
 * §75 rule 10): a 302 to a 5-minute temporary URL where the disk supports
 * one; otherwise the file is streamed, because a local temporary URL would
 * not be tenant-scoped. The caller gets the uploaded name.
 */
final class PrivateFileResponder
{
    public function respond(?Media $media): Response
    {
        if ($media === null) {
            throw new ApiException('file_unavailable', 'This file is no longer available.', 410);
        }

        $disk = Storage::disk($media->disk);

        if (! MediaDisks::isLocal($media->disk) && $disk->providesTemporaryUrls()) {
            try {
                return redirect()->away($media->getTemporaryUrl(now()->addMinutes(5)));
            } catch (Throwable) {
                // Fall back to streaming below.
            }
        }

        $extension = pathinfo($media->file_name, PATHINFO_EXTENSION);
        $name = $media->name !== '' ? $media->name.($extension === '' || str_ends_with(strtolower($media->name), '.'.strtolower($extension)) ? '' : '.'.$extension) : $media->file_name;

        return $disk->download($media->getPathRelativeToRoot(), $name);
    }
}
