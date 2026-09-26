<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Shared;

use App\Shared\Exceptions\ApiException;
use App\Shared\Exceptions\ExceptionRenderer;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use App\Shared\Idempotency\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * idempotency (spec §70.11). Requires an Idempotency-Key and replays, or
 * rejects, repeated requests. Services behind it also write the key onto the
 * record they create under a unique index (the second line of defence).
 */
final class EnforceIdempotency
{
    private const int LEASE_SECONDS = 60;

    private const int TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');

        if (preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $key) !== 1) {
            throw new ApiException(
                'validation_failed',
                'A valid Idempotency-Key header is required.',
                422,
                [],
                ['Idempotency-Key' => ['A valid Idempotency-Key header (8-128 characters, A-Z a-z 0-9 _ -) is required.']],
            );
        }

        $scope = $this->scope($request);
        $route = (string) ($request->route()?->getName() ?? $request->route()?->uri() ?? $request->path());
        $hash = $this->requestHash($request);

        $record = $this->claim($scope, $route, $key, $hash);

        if ($record instanceof Response) {
            return $record;
        }

        $request->attributes->set('idempotency_key', $key);

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $exception) {
            $response = ExceptionRenderer::render($exception, $request);

            if ($response->getStatusCode() >= 500) {
                $record->delete();

                throw $exception;
            }
        }

        if ($response->getStatusCode() >= 500) {
            $record->delete();

            return $response;
        }

        $record->forceFill([
            'status' => IdempotencyKey::COMPLETED,
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
        ])->save();

        return $response;
    }

    /**
     * Insert the processing row, or resolve what an existing row means.
     */
    private function claim(string $scope, string $route, string $key, string $hash): IdempotencyKey|Response
    {
        try {
            return IdempotencyKey::query()->create([
                'scope' => $scope,
                'route' => $route,
                'key' => $key,
                'request_hash' => $hash,
                'status' => IdempotencyKey::PROCESSING,
                'locked_until' => now()->addSeconds(self::LEASE_SECONDS),
                'created_at' => now(),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            /** @var IdempotencyKey $existing */
            $existing = IdempotencyKey::query()
                ->where('scope', $scope)->where('route', $route)->where('key', $key)
                ->firstOrFail();

            if ($existing->request_hash !== $hash) {
                throw ApiException::conflict('idempotency_conflict', 'This Idempotency-Key was already used for a different request.');
            }

            if ($existing->status === IdempotencyKey::COMPLETED) {
                return new IlluminateResponse((string) $existing->response_body, (int) $existing->response_status, [
                    'Content-Type' => 'application/json',
                    'Idempotent-Replayed' => 'true',
                ]);
            }

            if ($existing->locked_until->isFuture()) {
                throw new ApiException('idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.', 409);
            }

            $existing->forceFill(['locked_until' => now()->addSeconds(self::LEASE_SECONDS)])->save();

            return $existing;
        }
    }

    private function scope(Request $request): string
    {
        $user = Auth::user();

        if ($user !== null) {
            return Auth::getDefaultDriver().':'.$user->getAuthIdentifier();
        }

        $guestToken = ResolveGuestToken::from($request);

        if ($guestToken !== null) {
            return 'guest:'.hash('sha256', $guestToken);
        }

        return 'anonymous:'.hash('sha256', (string) $request->ip());
    }

    private function requestHash(Request $request): string
    {
        $body = $request->all();
        $this->sortRecursive($body);

        $parameters = $request->route()?->originalParameters() ?? [];
        ksort($parameters);

        return hash('sha256', (string) json_encode([$body, $parameters]));
    }

    /**
     * @param  array<mixed>  $value
     */
    private function sortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }
    }
}
