<?php

declare(strict_types=1);

use App\Shared\Media\MediaDisks;
use Illuminate\Support\Facades\Storage;

it('keeps each tenant under tenants/{id} on both media disks, with working public URLs', function (): void {
    $tenant = $this->createTenant('a');
    $base = base_path('storage/framework/testing/media');

    Storage::disk(MediaDisks::PUBLIC)->put('platform-logo.png', 'central');
    expect(Storage::disk(MediaDisks::PUBLIC)->url('platform-logo.png'))->toBe('http://platform.test/storage/media/platform-logo.png');

    tenancy()->initialize($tenant);

    Storage::disk(MediaDisks::PUBLIC)->put('1/shoe.jpg', 'image');
    Storage::disk(MediaDisks::PRIVATE)->put('2/receipt.pdf', 'pdf');

    expect(is_file($base.'/public/tenants/test-tenant-a/1/shoe.jpg'))->toBeTrue()
        ->and(is_file($base.'/private/tenants/test-tenant-a/2/receipt.pdf'))->toBeTrue()
        ->and(Storage::disk(MediaDisks::PUBLIC)->url('1/shoe.jpg'))->toBe('http://platform.test/storage/media/tenants/test-tenant-a/1/shoe.jpg')
        // A tenant never sees central files, and platform URLs stay central.
        ->and(Storage::disk(MediaDisks::PUBLIC)->exists('platform-logo.png'))->toBeFalse()
        ->and(MediaDisks::centralUrl('platform-logo.png'))->toBe('http://platform.test/storage/media/platform-logo.png')
        ->and(MediaDisks::isLocal(MediaDisks::PRIVATE))->toBeTrue();

    tenancy()->end();

    expect(Storage::disk(MediaDisks::PUBLIC)->url('x.jpg'))->toBe('http://platform.test/storage/media/x.jpg');
});

it('lays out object storage as public/ and private/ prefixes with tenant keys inside', function (): void {
    $set = static function (string $key, ?string $value): void {
        $value === null ? putenv($key) : putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    };

    $previous = config('filesystems.disks');

    try {
        $set('MEDIA_DRIVER', 's3');
        $set('MEDIA_PUBLIC_URL', 'https://cdn.example.test');
        $set('AWS_BUCKET', 'platform-media');
        $disks = (static fn (): array => require config_path('filesystems.php'))()['disks'];
    } finally {
        $set('MEDIA_DRIVER', 'local');
        $set('MEDIA_PUBLIC_URL', null);
        $set('AWS_BUCKET', null);
    }

    expect($disks['media-public'])->toMatchArray(['driver' => 's3', 'bucket' => 'platform-media', 'root' => 'public', 'url' => 'https://cdn.example.test'])
        ->and($disks['media-public'])->not->toHaveKey('visibility')
        ->and($disks['media-private'])->toMatchArray(['driver' => 's3', 'root' => 'private', 'url' => null]);

    config(['filesystems.disks' => array_merge($previous, $disks)]);
    Storage::forgetDisk(MediaDisks::ALL);

    tenancy()->initialize($this->createTenant('a'));

    expect(config('filesystems.disks.media-public.root'))->toBe('public/tenants/test-tenant-a')
        ->and(config('filesystems.disks.media-private.root'))->toBe('private/tenants/test-tenant-a')
        ->and(MediaDisks::isLocal(MediaDisks::PRIVATE))->toBeFalse()
        // S3 URLs already carry the prefix; the local URL fix leaves them alone.
        ->and(Storage::disk(MediaDisks::PUBLIC)->url('1/shoe.jpg'))->toBe('https://cdn.example.test/public/tenants/test-tenant-a/1/shoe.jpg');

    tenancy()->end();
    config(['filesystems.disks' => $previous]);
    Storage::forgetDisk(MediaDisks::ALL);
});
