<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Access\Services\TenantDefaultsSyncService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Brings an existing (empty or outdated) tenant database to the current
 * schema and seeds the data every tenant needs (spec §9.4 steps 2 and 3).
 * Used by ProvisionTenantDatabase and by the test fixtures. Every step is
 * idempotent.
 */
final class TenantDatabasePreparer
{
    public function __construct(
        private readonly TenantDefaultsSyncService $defaults,
    ) {}

    public function prepare(Tenant $tenant): void
    {
        $this->migrate($tenant);

        $tenant->run(fn () => $this->defaults->sync($tenant));
    }

    public function migrate(Tenant $tenant): void
    {
        $exitCode = Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant->getTenantKey()],
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Tenant migrations failed: '.Artisan::output());
        }

        $tenant->forceFill(['schema_version' => $this->latestMigration()])->saveQuietly();
    }

    /**
     * The name of the newest tenant migration file: the schema version a
     * fully migrated tenant reports (spec §6.8).
     */
    public function latestMigration(): string
    {
        $files = $this->migrationFiles();

        return $files === [] ? '' : basename((string) end($files), '.php');
    }

    /**
     * A signature of everything that shapes a freshly prepared tenant
     * database: the migrations and the defaults version.
     */
    public function schemaSignature(): string
    {
        $parts = array_map(
            static fn (string $file): string => basename($file).':'.md5_file($file),
            $this->migrationFiles(),
        );

        $parts[] = 'defaults:'.$this->defaults->version();

        foreach (self::DEFAULT_DATA_SOURCES as $file) {
            $path = base_path($file);
            $parts[] = $file.':'.(is_file($path) ? md5_file($path) : '-');
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Configuration files the defaults sync seeds from.
     *
     * @var list<string>
     */
    private const array DEFAULT_DATA_SOURCES = [
        'config/notifications/tenant.php',
        'config/permissions/tenant.php',
        'config/permissions/generated/tenant.php',
        'config/modules.php',
    ];

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = [];

        foreach ((array) config('tenancy.migration_parameters.--path') as $path) {
            $files = array_merge($files, glob(rtrim((string) $path, '/\\').'/*.php') ?: []);
        }

        usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

        return $files;
    }
}
