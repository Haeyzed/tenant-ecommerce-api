<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Images of CMS records (og_image, cover_image, photo, banner image and
 * page section media). Public content, so stored on the public disk; in
 * the tenant scope uploads count towards max_storage_mb (spec §19.3).
 */
final readonly class CmsMedia
{
    public function __construct(private StorageQuota $quota) {}

    public function store(Model&HasMedia $record, string $collection, UploadedFile $file): Media
    {
        Validator::make(['image' => $file], ['image' => ['required', ...UploadRules::image()]])->validate();

        // Landlord content has no storage limit; StorageQuota is a no-op there.
        $this->quota->assertAllows($file);

        return $record->addMedia($file)
            ->usingFileName(Str::uuid().'.'.$file->guessExtension())
            ->usingName(mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 200))
            ->toMediaCollection($collection);
    }

    public function url(Model&HasMedia $record, string $collection): ?string
    {
        $media = $record->getFirstMedia($collection);

        return $media?->getUrl();
    }
}
