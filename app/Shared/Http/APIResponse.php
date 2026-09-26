<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use JsonSerializable;
use stdClass;

/**
 * The single response envelope of the API (spec §70.1):
 * {success, message, data, meta, errors}.
 */
final class APIResponse
{
    /**
     * Build a success response. Paginated resource collections put their
     * pagination into meta.pagination and meta.links (spec §70.4).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        [$payload, $paginationMeta] = self::resolveData($data);

        return self::make(true, $message, $payload, array_merge($paginationMeta, $meta), [], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::success($data, $message, [], 201);
    }

    public static function accepted(mixed $data = null, string $message = 'Accepted'): JsonResponse
    {
        return self::success($data, $message, [], 202);
    }

    public static function noContent(string $message = 'Deleted'): JsonResponse
    {
        return self::make(true, $message, null, [], [], 200);
    }

    /**
     * Build an error response (spec §70.8).
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $errors
     */
    public static function error(
        string $errorCode,
        string $message,
        int $status,
        array $details = [],
        array $errors = [],
    ): JsonResponse {
        return self::make(
            false,
            $message,
            null,
            ['error_code' => $errorCode, 'details' => $details === [] ? new stdClass : $details],
            $errors,
            $status,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $errors
     */
    private static function make(bool $success, string $message, mixed $data, array $meta, array $errors, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'meta' => $meta === [] ? new stdClass : $meta,
            'errors' => $errors === [] ? new stdClass : $errors,
        ], $status);
    }

    /**
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private static function resolveData(mixed $data): array
    {
        $request = request();

        if ($data instanceof ResourceCollection && $data->resource instanceof AbstractPaginator
            || $data instanceof ResourceCollection && $data->resource instanceof AbstractCursorPaginator) {
            $paginator = $data->resource;

            // resolve(), not toArray(): it drops conditional (when()) fields.
            return [$data->collection->map->resolve($request)->all(), self::paginationMeta($paginator)];
        }

        if ($data instanceof JsonResource) {
            return [$data->resolve($request), []];
        }

        if ($data instanceof AbstractPaginator || $data instanceof AbstractCursorPaginator) {
            return [$data->items(), self::paginationMeta($data)];
        }

        if ($data instanceof JsonSerializable) {
            return [$data->jsonSerialize(), []];
        }

        return [$data, []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paginationMeta(AbstractPaginator|AbstractCursorPaginator $paginator): array
    {
        if ($paginator instanceof AbstractCursorPaginator) {
            return [
                'pagination' => [
                    'per_page' => $paginator->perPage(),
                    'next_cursor' => $paginator->nextCursor()?->encode(),
                    'prev_cursor' => $paginator->previousCursor()?->encode(),
                ],
                'links' => [
                    'next' => $paginator->nextPageUrl(),
                    'prev' => $paginator->previousPageUrl(),
                ],
            ];
        }

        $pagination = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        $links = [
            'first' => $paginator->url(1),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $pagination['total'] = $paginator->total();
            $pagination['last_page'] = $paginator->lastPage();
            $links['last'] = $paginator->url($paginator->lastPage());
        }

        return ['pagination' => $pagination, 'links' => $links];
    }
}
