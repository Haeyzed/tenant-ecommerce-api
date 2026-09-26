<?php

declare(strict_types=1);

namespace App\Shared\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

/**
 * Landlord media live under "landlord/" (spec §6.3, §24.1); tenant media
 * are already isolated under "tenants/{id}/" by the filesystem
 * bootstrapper, so tenant paths are unchanged.
 */
final class ContextPathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        $base = parent::getBasePath($media);

        return tenancy()->initialized ? $base : 'landlord/'.$base;
    }
}
