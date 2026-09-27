<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Shared\Media\MediaDisks;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Runs after FilesystemTenancyBootstrapper. That bootstrapper moves the
 * media-public root to `…/tenants/{id}`, but a local disk builds URLs
 * from its `url` without the root, so tenant images would point at the
 * central folder. This adds the same suffix to the URL. Object storage
 * (S3) already includes the root in its URLs and is left alone.
 */
final class MediaUrlTenancyBootstrapper implements TenancyBootstrapper
{
    private ?string $originalUrl = null;

    public function __construct(private readonly Config $config) {}

    public function bootstrap(Tenant $tenant): void
    {
        if (! MediaDisks::isLocal(MediaDisks::PUBLIC)) {
            return;
        }

        $key = 'filesystems.disks.'.MediaDisks::PUBLIC.'.url';
        $this->originalUrl ??= (string) $this->config->get($key);

        $this->config->set($key, rtrim($this->originalUrl, '/').'/'.trim((string) $this->config->get('tenancy.filesystem.suffix_base'), '/').'/'.$tenant->getTenantKey());
        Storage::forgetDisk(MediaDisks::PUBLIC);
    }

    public function revert(): void
    {
        if ($this->originalUrl === null) {
            return;
        }

        $this->config->set('filesystems.disks.'.MediaDisks::PUBLIC.'.url', $this->originalUrl);
        $this->originalUrl = null;
        Storage::forgetDisk(MediaDisks::PUBLIC);
    }
}
