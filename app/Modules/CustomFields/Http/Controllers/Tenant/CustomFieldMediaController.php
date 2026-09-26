<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Services\CustomFieldService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * A custom-field file behind a short-lived signed URL (spec §23.3, §75
 * rule 10). Only media of the custom_fields collection are served here.
 */
final class CustomFieldMediaController extends Controller
{
    public function __invoke(int $media): Response
    {
        $file = Media::query()->whereKey($media)->where('collection_name', CustomFieldService::MEDIA_COLLECTION)->firstOrFail();

        return $file->toResponse(request());
    }
}
