<?php

declare(strict_types=1);

namespace App\Shared\Media;

use Illuminate\Support\Facades\Storage;

/**
 * The two logical media disks (config/filesystems.php). Code names only
 * these; MEDIA_DRIVER decides whether they are local folders or object
 * storage. Both are tenant-scoped by the filesystem bootstrapper.
 */
final class MediaDisks
{
    /** Storefront and website images: served by URL (or CDN). */
    public const string PUBLIC = 'media-public';

    /** Receipts, exports, downloads, attachments: never a public URL. */
    public const string PRIVATE = 'media-private';

    public const array ALL = [self::PUBLIC, self::PRIVATE];

    /** An alias of PUBLIC outside tenancy, for URLs only (centralUrl). */
    private const string PUBLIC_CENTRAL = 'media-public-central';

    /**
     * A local disk has no signed temporary URLs that are tenant-scoped, so
     * its private files are streamed after the authorisation check.
     */
    public static function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    /**
     * The URL of a platform (central) public file from any context:
     * media-public-central is the same storage, never re-rooted by tenancy.
     */
    public static function centralUrl(string $path): string
    {
        return Storage::disk(self::PUBLIC_CENTRAL)->url($path);
    }
}
