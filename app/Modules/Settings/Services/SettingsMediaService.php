<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Cms\Support\CmsMedia;
use App\Modules\Settings\Models\PlatformSetting;
use App\Modules\Settings\Models\StorefrontSetting;
use App\Modules\Settings\Models\TenantSetting;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

/**
 * Image settings (BG-12): the store logo, favicon and share image, and the
 * platform's. The file is kept on the setting's own row (single-file
 * "image" collection, public disk) and the setting stores its media id, so
 * every existing reader (storefront config, emails, onboarding) is
 * unchanged. Replacing an image removes the previous file.
 */
final readonly class SettingsMediaService
{
    /** Upload slot => [model, setting key]. */
    public const array TENANT = [
        'store_logo' => [TenantSetting::class, 'store_logo_media_id'],
        'favicon' => [TenantSetting::class, 'favicon_media_id'],
        'seo_share_image' => [StorefrontSetting::class, 'seo_share_image_media_id'],
    ];

    public const array PLATFORM = [
        'platform_logo' => [PlatformSetting::class, 'platform_logo_media_id'],
        'platform_favicon' => [PlatformSetting::class, 'platform_favicon_media_id'],
        'seo_share_image' => [PlatformSetting::class, 'seo_share_image_media_id'],
    ];

    public function __construct(
        private CmsMedia $media,
        private TenantSettingsService $tenantSettings,
        private StorefrontSettingsService $storefrontSettings,
        private PlatformSettingsService $platformSettings,
    ) {}

    /**
     * @return array{setting: string, media_id: int, url: string}
     */
    public function upload(string $slot, UploadedFile $file, bool $platform): array
    {
        [$model, $key] = $this->slot($slot, $platform);

        // The row must exist before a file can be attached to it.
        $current = $this->read($model, $key);
        $this->write($model, $key, is_numeric($current) ? (int) $current : null);
        $row = $model::query()->where('key', $key)->firstOrFail();

        $stored = $this->media->store($row, 'image', $file);
        $this->write($model, $key, $stored->id);

        return ['setting' => $slot, 'media_id' => $stored->id, 'url' => $stored->getUrl()];
    }

    public function remove(string $slot, bool $platform): void
    {
        [$model, $key] = $this->slot($slot, $platform);

        $model::query()->where('key', $key)->first()?->clearMediaCollection('image');
        $this->write($model, $key, null);
    }

    /**
     * @return array{0: class-string<TenantSetting|StorefrontSetting|PlatformSetting>, 1: string}
     */
    private function slot(string $slot, bool $platform): array
    {
        return ($platform ? self::PLATFORM : self::TENANT)[$slot]
            ?? throw ApiException::unprocessable('unknown_image_setting', 'Choose one of: '.implode(', ', array_keys($platform ? self::PLATFORM : self::TENANT)).'.');
    }

    private function read(string $model, string $key): mixed
    {
        return match ($model) {
            TenantSetting::class => $this->tenantSettings->get($key),
            StorefrontSetting::class => $this->storefrontSettings->get($key),
            default => $this->platformSettings->get($key),
        };
    }

    private function write(string $model, string $key, ?int $mediaId): void
    {
        match ($model) {
            TenantSetting::class => $this->tenantSettings->set($key, $mediaId),
            StorefrontSetting::class => $this->storefrontSettings->update([$key => $mediaId]),
            default => $this->platformSettings->set($key, $mediaId),
        };
    }
}
