<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Drivers;

use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Shop Open API (version 202309). Every call is signed with the
 * platform's app key and secret; account_reference is the shop cipher.
 * TikTok listings need a category, brand and package details that this
 * catalogue does not hold, so products are listed in Seller Center and
 * matched here by seller SKU: a push updates their price and stock, and an
 * unmatched product is marked rejected with the reason (D-131).
 */
final class TikTokShopDriver implements SocialChannelDriver
{
    public function verify(SocialCommerceAccount $account): void
    {
        $this->call($account, 'GET', '/authorization/202309/shops');
    }

    public function upsert(SocialCommerceAccount $account, array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            $sku = (string) ($item['sku'] ?? '');

            if ($sku === '') {
                $results[$item['retailer_id']] = ['external_id' => null, 'rejection' => 'Give the product a SKU and list it in TikTok Shop Seller Center with that seller SKU.'];

                continue;
            }

            $found = $this->call($account, 'POST', '/product/202309/products/search', ['page_size' => 1], ['seller_skus' => [$sku]]);
            $product = $found['data']['products'][0] ?? null;
            $remoteSku = collect((array) ($product['skus'] ?? []))->firstWhere('seller_sku', $sku);

            if ($product === null || $remoteSku === null) {
                $results[$item['retailer_id']] = ['external_id' => null, 'rejection' => "No TikTok Shop listing has seller SKU {$sku}. List it in Seller Center first."];

                continue;
            }

            $this->call($account, 'POST', "/product/202309/products/{$product['id']}/prices/update", [], ['skus' => [['id' => $remoteSku['id'], 'price' => ['amount' => $item['price'], 'currency' => $item['currency']]]]]);
            $this->call($account, 'POST', "/product/202309/products/{$product['id']}/inventory/update", [], ['skus' => [['id' => $remoteSku['id'], 'inventory' => [['quantity' => $item['quantity']]]]]]);
            $results[$item['retailer_id']] = ['external_id' => (string) $product['id'], 'rejection' => null];
        }

        return $results;
    }

    public function remove(SocialCommerceAccount $account, array $externalIds): void
    {
        if ($externalIds !== []) {
            $this->call($account, 'POST', '/product/202309/products/deactivate', [], ['product_ids' => array_values($externalIds)]);
        }
    }

    public function orders(SocialCommerceAccount $account, CarbonImmutable $since): array
    {
        $orders = [];
        $token = null;

        for ($page = 0; $page < 20; $page++) {
            $response = $this->call($account, 'POST', '/order/202309/orders/search', array_filter(['page_size' => 50, 'page_token' => $token]), ['update_time_ge' => $since->getTimestamp()]);

            foreach ((array) ($response['data']['orders'] ?? []) as $order) {
                $orders[] = $this->normalise($order);
            }

            $token = $response['data']['next_page_token'] ?? null;

            if ($token === null || $token === '') {
                break;
            }
        }

        return $orders;
    }

    /**
     * TikTok lists one line item per unit: they are grouped by SKU, and the
     * order's tax is spread over the lines by amount.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function normalise(array $order): array
    {
        $payment = (array) ($order['payment'] ?? []);
        $lines = [];

        foreach ((array) ($order['line_items'] ?? []) as $item) {
            $sku = (string) ($item['seller_sku'] ?? '');
            $lines[$sku] ??= ['retailer_id' => null, 'sku' => $sku, 'quantity' => '0', 'unit_price' => Money::normalize((string) ($item['sale_price'] ?? '0')), 'tax' => '0'];
            $lines[$sku]['quantity'] = (string) ((int) $lines[$sku]['quantity'] + 1);
        }

        $lines = array_values($lines);
        $tax = Money::normalize((string) ($payment['tax'] ?? '0'));
        $base = array_reduce($lines, static fn (string $sum, array $l): string => Money::add($sum, bcmul($l['unit_price'], $l['quantity'], 4)), '0');
        $left = $tax;

        foreach ($lines as $i => $line) {
            $share = $i === count($lines) - 1 || ! Money::isPositive($base) ? $left : bcdiv(bcmul($tax, bcmul($line['unit_price'], $line['quantity'], 4), 8), $base, 2);
            $lines[$i]['tax'] = $share;
            $left = Money::sub($left, $share);
        }

        $address = (array) ($order['recipient_address'] ?? []);
        $status = (string) ($order['status'] ?? '');

        return [
            'id' => (string) $order['id'],
            'currency' => (string) ($payment['currency'] ?? ''),
            'email' => $order['buyer_email'] ?? null,
            'name' => $address['name'] ?? null,
            'phone' => $address['phone_number'] ?? null,
            'address' => $address === [] ? null : [
                'name' => $address['name'] ?? null, 'line1' => $address['address_line1'] ?? '', 'line2' => $address['address_line2'] ?? null,
                'city' => null, 'postal_code' => $address['postal_code'] ?? null, 'country' => $address['region_code'] ?? null, 'state' => null,
            ],
            'lines' => $lines,
            'shipping' => (string) ($payment['shipping_fee'] ?? '0'),
            'shipping_tax' => '0',
            'total' => (string) ($payment['total_amount'] ?? '0'),
            'paid' => ! in_array($status, ['UNPAID', 'CANCELLED'], true),
            'cancelled' => $status === 'CANCELLED',
            'created_at' => isset($order['create_time']) ? CarbonImmutable::createFromTimestampUTC((int) $order['create_time'])->toIso8601String() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function call(SocialCommerceAccount $account, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $key = (string) config('integrations.social_commerce.tiktok_app_key');
        $secret = (string) config('integrations.social_commerce.tiktok_app_secret');

        if ($key === '' || $secret === '') {
            throw new SocialCommerceException('TikTok Shop is not set up on this platform yet.');
        }

        $query = [...$query, 'app_key' => $key, 'timestamp' => now()->getTimestamp(), 'shop_cipher' => $account->account_reference];
        $json = $body === null ? '' : (string) json_encode($body);
        $query['sign'] = self::sign($secret, $path, $query, $json);

        try {
            $request = Http::baseUrl(rtrim((string) config('integrations.social_commerce.tiktok_api_url'), '/'))
                ->withHeaders(['x-tts-access-token' => $account->access_token, 'content-type' => 'application/json'])
                ->connectTimeout((int) config('integrations.http.connect_timeout', 10))
                ->timeout((int) config('integrations.http.timeout', 30))
                ->withoutRedirecting()->acceptJson();
            $url = $path.'?'.http_build_query($query);
            $response = $method === 'GET' ? $request->get($url) : $request->withBody($json === '' ? '{}' : $json, 'application/json')->post($url);
        } catch (ConnectionException) {
            throw new SocialCommerceException('TikTok Shop could not be reached.');
        }

        $code = (int) $response->json('code', $response->successful() ? 0 : $response->status());

        if ($code === 0 && $response->successful()) {
            return (array) $response->json();
        }

        // 105001–105005: the access token is invalid or expired.
        if ($code >= 105001 && $code <= 105005) {
            throw new SocialCommerceException('The TikTok Shop access token has expired. Reconnect the account.');
        }

        throw new SocialCommerceException('TikTok Shop error: '.mb_substr((string) ($response->json('message') ?? 'HTTP '.$response->status()), 0, 250));
    }

    /**
     * HMAC-SHA256 over secret + path + the sorted query (without sign and
     * access_token) + body + secret.
     *
     * @param  array<string, mixed>  $query
     */
    public static function sign(string $secret, string $path, array $query, string $body): string
    {
        unset($query['sign'], $query['access_token']);
        ksort($query);
        $plain = $path;

        foreach ($query as $k => $v) {
            $plain .= $k.$v;
        }

        return hash_hmac('sha256', $secret.$plain.$body.$secret, $secret);
    }
}
