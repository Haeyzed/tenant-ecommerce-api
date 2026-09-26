<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\Http\APIResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every exception through the APIResponse envelope (spec §70.8),
 * so middleware and framework errors never produce a second format.
 */
final class ExceptionRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => $e->render(),
            $e instanceof ValidationException => APIResponse::error(
                'validation_failed',
                $e->getMessage(),
                422,
                [],
                $e->errors(),
            ),
            $e instanceof AuthenticationException => APIResponse::error('unauthenticated', 'Unauthenticated.', 401),
            $e instanceof AuthorizationException, $e instanceof UnauthorizedException => APIResponse::error(
                'forbidden',
                'You are not allowed to perform this action.',
                403,
            ),
            $e instanceof InvalidSignatureException => APIResponse::error('invalid_signature', 'The link is invalid or has expired.', 403),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException,
            $e instanceof TenantCouldNotBeIdentifiedException => APIResponse::error('not_found', 'The requested resource was not found.', 404),
            $e instanceof ThrottleRequestsException => self::withHeaders(
                APIResponse::error('too_many_requests', 'Too many requests.', 429),
                $e->getHeaders(),
            ),
            $e instanceof MethodNotAllowedHttpException => APIResponse::error('method_not_allowed', 'Method not allowed.', 405),
            $e instanceof HttpExceptionInterface => self::withHeaders(
                APIResponse::error(self::codeForStatus($e->getStatusCode()), $e->getMessage() ?: 'Request failed.', $e->getStatusCode()),
                $e->getHeaders(),
            ),
            default => self::serverError($e),
        };
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        $details = config('app.debug') && app()->environment('local', 'testing')
            ? ['exception' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]
            : [];

        return APIResponse::error('server_error', 'Something went wrong.', 500, $details);
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            402 => 'payment_required',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'state_conflict',
            410 => 'gone',
            419 => 'session_expired',
            422 => 'validation_failed',
            429 => 'too_many_requests',
            503 => 'maintenance',
            default => 'http_error',
        };
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function withHeaders(JsonResponse $response, array $headers): JsonResponse
    {
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
