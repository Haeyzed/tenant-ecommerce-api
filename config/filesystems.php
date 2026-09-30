<?php

/*
| Media disks (spec §6.3, A-6): the code only ever names `media-public`
| (storefront images, served by URL or CDN) and `media-private` (receipts,
| exports, downloads, attachments; served by signed URL or streamed).
| MEDIA_DRIVER picks where they live: `local` folders for development, or
| `s3` for any S3-compatible object storage (AWS S3, Cloudflare R2,
| DigitalOcean Spaces, Backblaze B2, MinIO). The platform owns the storage;
| the tenancy filesystem bootstrapper gives every tenant a `tenants/{id}/`
| prefix on both disks. S3 disks set no per-object visibility: public
| access comes from the bucket policy or CDN (object ACLs are disabled on
| modern buckets).
*/

$mediaDriver = env('MEDIA_DRIVER', 'local');

// A root given in .env is relative to the project unless absolute.
$localRoot = static function (?string $configured, string $default): string {
    if ($configured === null || $configured === '') {
        return $default;
    }

    return preg_match('~^([A-Za-z]:)?[\\\\/]~', $configured) === 1 ? $configured : base_path($configured);
};

// One bucket can hold both disks: `public/…` is the only prefix its policy
// exposes; `private/…` stays private. Tenants nest under tenants/{id}/.
$s3 = static fn (?string $bucket, ?string $url, string $root): array => [
    'driver' => 's3',
    'root' => $root,
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'auto'),
    'bucket' => $bucket,
    'url' => $url,
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => true,
    'report' => false,
];

$mediaPublic = $mediaDriver === 's3'
    ? $s3((env('MEDIA_PUBLIC_BUCKET') ?: env('AWS_BUCKET')), env('MEDIA_PUBLIC_URL'), 'public')
    : [
        'driver' => 'local',
        // Under storage/app/public, so the storage:link symlink serves it.
        'root' => $localRoot(env('MEDIA_PUBLIC_ROOT'), storage_path('app/public/media')),
        'url' => env('MEDIA_PUBLIC_URL') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage/media',
        'visibility' => 'public',
        'throw' => true,
        'report' => false,
    ];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'media-public' => $mediaPublic,

        // The same storage, never re-rooted by tenancy: only for building
        // URLs of platform files from any context (MediaDisks::centralUrl).
        'media-public-central' => $mediaPublic,

        'media-private' => $mediaDriver === 's3'
            ? $s3((env('MEDIA_PRIVATE_BUCKET') ?: env('AWS_BUCKET')), null, 'private')
            : [
                'driver' => 'local',
                // Never web-served: private files are streamed after an authorisation check.
                'root' => $localRoot(env('MEDIA_PRIVATE_ROOT'), storage_path('app/private/media')),
                'throw' => true,
                'report' => false,
            ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
