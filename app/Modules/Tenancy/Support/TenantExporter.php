<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * A full, portable copy of one tenant (spec §6.7): every table of its
 * database as JSON lines (chunked, never whole tables in memory) and every
 * file under its storage prefix. Used for data-portability exports and the
 * final backup before a purge.
 */
final class TenantExporter
{
    private const int CHUNK = 1000;

    /**
     * Writes the archive to the local disk and returns its path there.
     */
    public function export(Tenant $tenant, string $directory): string
    {
        $disk = Storage::disk('local');
        $relative = trim($directory, '/').'/'.$tenant->getTenantKey().'-'.now()->format('YmdHis').'.zip';
        $disk->makeDirectory(dirname($relative));

        $zip = new ZipArchive;

        if ($zip->open($disk->path($relative), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }

        $manifest = ['tenant_id' => (string) $tenant->getTenantKey(), 'name' => $tenant->name, 'exported_at' => now()->toIso8601String(), 'tables' => []];
        $scratch = [];

        $tenant->run(function () use ($zip, &$manifest, &$scratch): void {
            $connection = DB::connection('tenant');

            foreach ($connection->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
                $file = tempnam(sys_get_temp_dir(), 'tex');
                $scratch[] = $file;
                $handle = fopen($file, 'wb');
                $rows = 0;

                $connection->table($table)->orderByRaw('1')->lazy(self::CHUNK)->each(static function (object $row) use ($handle, &$rows): void {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
                    $rows++;
                });

                fclose($handle);
                $zip->addFile($file, 'database/'.$table.'.jsonl');
                $manifest['tables'][$table] = $rows;
            }

            // The filesystem bootstrapper scopes the disk to this tenant.
            $files = Storage::disk('local');

            foreach ($files->allFiles() as $path) {
                $zip->addFile($files->path($path), 'files/'.$path);
            }
        });

        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->close();

        foreach ($scratch as $file) {
            @unlink($file);
        }

        return $relative;
    }
}
