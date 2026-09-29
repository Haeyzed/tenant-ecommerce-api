<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires projects into the custom-field registry (§23.1).
 */
final class ProjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register(ProjectService::ENTITY, Project::class, 'projects', 'project_management');
        });
    }
}
