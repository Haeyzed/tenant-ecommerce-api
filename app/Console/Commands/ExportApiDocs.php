<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Exports the landlord and tenant OpenAPI documents (dedoc/scramble) to
 * docs/api for Postman or any OpenAPI client.
 *
 * Scramble reads model columns from a live schema, and the tenant models
 * use the `tenant` connection, which only exists inside a tenant. This
 * command keeps a schema-only database ({TENANCY_DB_PREFIX}docs_schema)
 * migrated to the current tenant schema and points `tenant` at it for the
 * export. The database holds no data and is never a tenant.
 */
#[Signature('api-docs:export {--fresh : Rebuild the schema database from scratch}')]
#[Description('Export the landlord and tenant OpenAPI documents to docs/api')]
final class ExportApiDocs extends Command
{
    public function handle(): int
    {
        $database = config('tenancy.database.prefix').'docs_schema';

        if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            $this->error("Refusing an unsafe schema database name [{$database}].");

            return self::FAILURE;
        }

        $this->prepareSchema($database);
        File::ensureDirectoryExists(base_path('docs/api'));

        $landlordDefault = config('database.default');

        foreach (['default' => 'landlord', 'tenant' => 'tenant'] as $api => $name) {
            // Inside a tenant the default connection is the tenant's (models
            // without an explicit connection, such as User, resolve there).
            config(['database.default' => $name === 'tenant' ? 'tenant' : $landlordDefault]);
            $this->call('scramble:export', ['--api' => $api, '--path' => "docs/api/{$name}.openapi.json"]);
        }

        config(['database.default' => $landlordDefault]);

        $this->newLine();
        $this->info('Import docs/api/landlord.openapi.json and docs/api/tenant.openapi.json into Postman (Import → File).');

        return self::SUCCESS;
    }

    private function prepareSchema(string $database): void
    {
        $landlord = DB::connection('landlord');

        if ($this->option('fresh')) {
            $landlord->statement("DROP DATABASE IF EXISTS `{$database}`");
        }

        $landlord->statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        config(['database.connections.tenant' => array_merge((array) config('database.connections.tenant_template'), [
            'host' => config('database.connections.tenant_template.host') ?: config('database.connections.landlord.host'),
            'port' => config('database.connections.tenant_template.port') ?: config('database.connections.landlord.port'),
            'username' => config('database.connections.tenant_template.username') ?: config('database.connections.landlord.username'),
            'password' => config('database.connections.tenant_template.password') ?: config('database.connections.landlord.password'),
            'database' => $database,
        ])]);
        DB::purge('tenant');

        $this->components->task("Migrating the schema database {$database}", fn (): bool => $this->callSilently('migrate', [
            '--database' => 'tenant',
            '--path' => (array) config('tenancy.migration_parameters.--path'),
            '--realpath' => true,
            '--force' => true,
        ]) === self::SUCCESS);
    }
}
