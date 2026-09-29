<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Exports\Support\ExportContext;
use App\Modules\Exports\Support\ExportDefinition;
use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Reporting\Services\ReportService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\ServiceProvider;

/**
 * Every report is also an export type, report:{key} (spec §61.1), in CSV
 * or PDF. The rows are the report's, for the user who asked: their staff
 * scope comes from the export's requester, never from the parameters.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ExportRegistry::class, function (ExportRegistry $registry): void {
            foreach (ReportService::catalogue() as $key => [, $feature, $rules]) {
                $run = fn (array $parameters) => $this->app->make(ReportService::class)->run($key, $parameters, self::requester($this->app->make(ExportContext::class)));

                $registry->register(new ExportDefinition(
                    type: 'report:'.$key,
                    label: 'Report: '.str_replace(['/', '-'], [' – ', ' '], $key),
                    rules: $rules,
                    columns: [],
                    rows: static fn (array $parameters): iterable => $run($parameters)->stream(),
                    formats: ['csv', 'pdf'],
                    permission: ReportService::permissionOf($key),
                    module: 'advanced_reporting',
                    requires: $feature === null ? [] : [$feature],
                    columnsFor: static fn (array $parameters): array => array_column($run($parameters)->columns, 'label', 'key'),
                ));
            }
        });
    }

    private static function requester(ExportContext $context): User
    {
        return $context->requestedBy instanceof User ? $context->requestedBy
            : throw ApiException::forbidden('forbidden', 'Reports are exported by staff only.');
    }
}
