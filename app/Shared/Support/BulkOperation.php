<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Runs one bulk action (spec §70.12): each id goes through the same
 * service method as the single-item endpoint, independently (one bad item
 * never rolls back the others), and the run is summarised once in the
 * activity log under an operation id. At most 100 ids arrive per request,
 * so a run is bounded and synchronous.
 */
final class BulkOperation
{
    public const int MAX_ITEMS = 100;

    /**
     * @param  list<int|string>  $ids
     * @param  callable(int): void  $each  applies the action to one id; throws to report a failure
     * @return array{operation_id: string, succeeded: int, failed: int, results: list<array{id: int, status: string, error: string|null, message: string|null}>}
     */
    public static function run(string $resource, string $action, array $ids, callable $each): array
    {
        $operationId = (string) Str::uuid();
        $results = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $each($id);
                $results[] = ['id' => $id, 'status' => 'ok', 'error' => null, 'message' => null];
            } catch (Throwable $e) {
                $results[] = ['id' => $id, 'status' => 'error', ...self::describe($e)];
            }
        }

        $failed = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'error'));
        $summary = ['operation_id' => $operationId, 'succeeded' => count($results) - $failed, 'failed' => $failed, 'results' => $results];

        ActivityRecorder::tenant('bulk_actions', "Bulk {$action} on {$resource}", null, [
            'operation_id' => $operationId,
            'resource' => $resource,
            'action' => $action,
            'succeeded_ids' => array_column(array_filter($results, static fn (array $r): bool => $r['status'] === 'ok'), 'id'),
            'failed_ids' => array_column(array_filter($results, static fn (array $r): bool => $r['status'] === 'error'), 'id'),
        ]);

        return $summary;
    }

    /**
     * @return array{error: string, message: string}
     */
    private static function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => ['error' => $e->errorCode, 'message' => $e->getMessage()],
            $e instanceof ValidationException => ['error' => 'validation_failed', 'message' => (string) collect($e->errors())->flatten()->first()],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ['error' => 'not_found', 'message' => 'Not found.'],
            default => ['error' => 'failed', 'message' => 'This item could not be updated.'],
        };
    }
}
