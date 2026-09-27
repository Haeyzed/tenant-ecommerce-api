<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Media\MediaDisks;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Name, logo, footer and direction for an email, by who sends it: the
 * platform (landlord notifications) or the store (tenant notifications).
 * Resolved when the mail is sent, so a rename or new logo applies to
 * queued mail too. Branding never blocks delivery: any lookup failure
 * falls back to the plain values.
 */
final readonly class MailBranding
{
    public const string DEFAULT_COLOR = '#4f46e5';

    public function __construct(private PlatformSettingsService $platform) {}

    /**
     * @return array{name: string, logo_url: ?string, footer: ?string, color: string, dir: string}
     */
    public function for(bool $platform): array
    {
        if ($platform || ! tenancy()->initialized) {
            return [
                'name' => $this->platformName(),
                'logo_url' => $this->logoUrl($this->platform->get('platform_logo_media_id'), 'landlord'),
                'footer' => $this->text($this->platform->get('support_email')),
                'color' => self::DEFAULT_COLOR,
                'dir' => 'ltr',
            ];
        }

        $settings = app(TenantSettingsService::class);

        return [
            'name' => $this->text($settings->get('store_name')) ?? $this->platformName(),
            'logo_url' => $this->logoUrl($settings->get('store_logo_media_id'), null),
            'footer' => $this->text($settings->get('store_contact_email')),
            'color' => self::DEFAULT_COLOR,
            'dir' => (bool) $settings->get('rtl_enabled', false) ? 'rtl' : 'ltr',
        ];
    }

    private function platformName(): string
    {
        return $this->text($this->platform->get('platform_name')) ?? (string) config('app.name');
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Absolute http(s) URL only: a relative path is useless in an inbox.
     */
    private function logoUrl(mixed $mediaId, ?string $connection): ?string
    {
        if (! is_numeric($mediaId)) {
            return null;
        }

        try {
            $media = ($connection === null ? Media::query() : Media::on($connection))->find((int) $mediaId);

            // A platform file keeps its central URL even when a landlord
            // mail is sent from a tenant context (disks are re-rooted there).
            $url = $media !== null && $connection === 'landlord' && $media->disk === MediaDisks::PUBLIC
                ? MediaDisks::centralUrl($media->getPathRelativeToRoot())
                : $media?->getUrl();
        } catch (Throwable) {
            return null;
        }

        return is_string($url) && preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }
}
