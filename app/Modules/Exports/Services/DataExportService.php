<?php

declare(strict_types=1);

namespace App\Modules\Exports\Services;

use App\Modules\Exports\Jobs\GenerateExport;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * One background mechanism for every large export (spec §19.4).
 */
final readonly class DataExportService
{
    public function __construct(
        private ExportRegistry $registry,
        private FeatureAccessService $features,
    ) {}

    /**
     * Creates the export, or returns the identical queued or processing one.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function request(string $type, array $parameters, string $format, Model $requestedBy): DataExport
    {
        if (! $this->registry->has($type)) {
            throw ApiException::unprocessable('export_type_unknown', "Unknown export type [{$type}].");
        }

        $definition = $this->registry->get($type);

        validator(['format' => $format], ['format' => ['required', Rule::in($definition->formats)]])->validate();
        $validated = validator($parameters, $definition->rules)->validate();

        if ($definition->permission !== null && (! method_exists($requestedBy, 'hasPermissionTo') || ! $requestedBy->hasPermissionTo($definition->permission, 'staff'))) {
            throw ApiException::forbidden('forbidden', 'You are not allowed to export this data.', ['permission' => $definition->permission]);
        }

        /** @var Tenant $tenant */
        $tenant = tenant();

        if ($definition->module !== null && ! $this->features->canRead($tenant, $definition->module)) {
            throw ApiException::forbidden('feature_unavailable', 'This data is not available on your plan.', ['module' => $definition->module]);
        }

        ksort($validated);

        return DB::connection('tenant')->transaction(function () use ($type, $validated, $format, $requestedBy): DataExport {
            $existing = DataExport::query()
                ->where('export_type', $type)
                ->where('format', $format)
                ->where('requested_by_type', $requestedBy->getMorphClass())
                ->where('requested_by_id', $requestedBy->getKey())
                ->whereIn('status', [DataExport::QUEUED, DataExport::PROCESSING])
                ->lockForUpdate()
                ->get()
                ->first(static fn (DataExport $e): bool => $e->parameters == $validated);

            if ($existing !== null) {
                return $existing;
            }

            /** @var DataExport $export */
            $export = DataExport::query()->create([
                'export_type' => $type,
                'parameters' => $validated,
                'format' => $format,
                'status' => DataExport::QUEUED,
                'requested_by_type' => $requestedBy->getMorphClass(),
                'requested_by_id' => $requestedBy->getKey(),
            ]);

            GenerateExport::dispatch($export->id)->afterCommit();

            return $export;
        });
    }

    /**
     * Deletes expired files and marks their rows (daily tenant maintenance).
     */
    public function expireFiles(): int
    {
        $expired = 0;

        DataExport::query()
            ->where('status', DataExport::COMPLETED)
            ->where('expires_at', '<=', now())
            ->chunkById(200, static function ($exports) use (&$expired): void {
                foreach ($exports as $export) {
                    $export->clearMediaCollection('file');
                    $export->forceFill(['status' => DataExport::EXPIRED])->save();
                    $expired++;
                }
            });

        return $expired;
    }
}
