<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Queues (spec §72.1, §77.3)
|--------------------------------------------------------------------------
|
| Four named queues: landlord-default, tenant-critical, tenant-default and
| tenant-bulk. Queue storage (database driver locally, Redis in production)
| always lives in the landlord database, never a tenant database.
|
*/

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'names' => [
        'landlord' => 'landlord-default',
        'critical' => 'tenant-critical',
        'default' => 'tenant-default',
        'bulk' => 'tenant-bulk',
    ],

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION', 'landlord'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'tenant-default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 3700),
            'after_commit' => true,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'tenant-default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 3700),
            'block_for' => null,
            'after_commit' => true,
        ],

    ],

    'batching' => [
        'database' => 'landlord',
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => 'landlord',
        'table' => 'failed_jobs',
    ],

];
