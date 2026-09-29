<?php

declare(strict_types=1);

namespace App\Modules\Imports;

use App\Modules\Imports\Support\CoreImports;
use App\Modules\Imports\Support\ImportRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * The import registry (D-135): core-commerce types here; optional modules
 * add theirs with afterResolving(ImportRegistry::class, …).
 */
final class ImportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImportRegistry::class, static function (): ImportRegistry {
            $registry = new ImportRegistry;
            CoreImports::register($registry);

            return $registry;
        });
    }
}
