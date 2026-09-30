<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Contract\FrontendContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the frontend contract bundle (frontend spec BG-07): the route
 * manifests, module, limit and permission registries, error codes and
 * storefront options, plus the OpenAPI documents from docs/api. It is a
 * build artefact, not committed: the frontend's `pnpm contract:sync` runs
 * it with --path pointing into its contract package.
 *
 * --openapi re-exports the OpenAPI documents first (api-docs:export);
 * otherwise the last exported documents are copied.
 */
#[Signature('frontend:contract {--path=storage/app/frontend-contract : Output directory, absolute or relative to the project} {--openapi : Re-export the OpenAPI documents first}')]
#[Description('Export the frontend contract bundle (routes, modules, limits, permissions, error codes, OpenAPI)')]
final class ExportFrontendContract extends Command
{
    public function handle(FrontendContract $contract): int
    {
        $option = rtrim((string) $this->option('path'), '/\\');
        $absolute = str_starts_with($option, '/') || str_starts_with($option, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $option) === 1;
        $path = $absolute ? $option : base_path($option);
        File::ensureDirectoryExists($path);

        if ($this->option('openapi') && $this->call('api-docs:export') !== self::SUCCESS) {
            return self::FAILURE;
        }

        foreach ($contract->build() as $file => $data) {
            File::put($path.DIRECTORY_SEPARATOR.$file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            $this->components->twoColumnDetail($file, count($data).' entries');
        }

        foreach (['landlord', 'tenant'] as $api) {
            $source = base_path("docs/api/{$api}.openapi.json");

            if (File::exists($source)) {
                File::copy($source, $path.DIRECTORY_SEPARATOR."{$api}.openapi.json");
                $this->components->twoColumnDetail("{$api}.openapi.json", 'copied');
            } else {
                $this->components->warn("docs/api/{$api}.openapi.json is missing; run with --openapi.");
            }
        }

        $this->components->info("Frontend contract written to {$path}.");

        return self::SUCCESS;
    }
}
