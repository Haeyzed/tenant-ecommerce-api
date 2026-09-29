<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Support;

use App\Modules\Integrations\Support\PublicUrlGuard;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceSettings;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The WooCommerce REST API v3 (spec §68), authenticated with the store's
 * consumer key and secret over HTTPS (basic auth). The store URL is
 * re-checked before every call and redirects are never followed.
 */
final readonly class WooCommerceClient
{
    /** At most this many pages are read in one listing (100 items each). */
    private const int MAX_PAGES = 50;

    public function __construct(private WooCommerceSettings $settings) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return (array) $this->send('get', $path, $query)->json();
    }

    /**
     * Every page of a listing.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function all(string $path, array $query = []): array
    {
        $items = [];
        $perPage = (int) config('integrations.woocommerce.page_size', 100);

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->send('get', $path, [...$query, 'per_page' => $perPage, 'page' => $page]);
            $batch = (array) $response->json();
            array_push($items, ...array_values($batch));
            $pages = (int) ($response->header('X-WP-TotalPages') ?: 1);

            if ($page >= $pages || count($batch) < $perPage) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return (array) $this->send('post', $path, $body)->json();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body): array
    {
        return (array) $this->send('put', $path, $body)->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $path, array $data): Response
    {
        if (! $this->settings->hasCredentials()) {
            throw new WooCommerceException('The WooCommerce store is not connected.');
        }

        try {
            $base = PublicUrlGuard::assertPublic((string) $this->settings->store_url);
        } catch (ApiException $e) {
            throw new WooCommerceException($e->getMessage());
        }

        try {
            $response = $this->client($base)->{$method}(ltrim($path, '/'), $data);
        } catch (ConnectionException) {
            throw new WooCommerceException('The WooCommerce store could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new WooCommerceException('WooCommerce refused the consumer key and secret.', $response->status());
        }

        if (! $response->successful()) {
            $message = $response->json('message');

            throw new WooCommerceException('WooCommerce error: '.mb_substr(is_string($message) ? $message : 'HTTP '.$response->status(), 0, 250), $response->status());
        }

        return $response;
    }

    private function client(string $base): PendingRequest
    {
        return Http::baseUrl($base.'/wp-json/wc/v3/')
            ->withBasicAuth((string) $this->settings->consumer_key, (string) $this->settings->consumer_secret)
            ->connectTimeout((int) config('integrations.http.connect_timeout', 10))
            ->timeout((int) config('integrations.http.timeout', 30))
            ->withoutRedirecting()
            ->acceptJson()
            ->asJson();
    }
}
