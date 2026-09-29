<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Drivers;

use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Facebook Shop, Instagram shopping and the WhatsApp catalogue share a Meta
 * product catalogue (Graph API): items are upserted in batches keyed by our
 * retailer id, which is also the external id. Facebook Shop orders are read
 * from the commerce account (order_account_reference).
 */
final class MetaCatalogDriver implements SocialChannelDriver
{
    public function verify(SocialCommerceAccount $account): void
    {
        $this->call($account, 'get', $account->account_reference, ['fields' => 'id,name']);
    }

    public function upsert(SocialCommerceAccount $account, array $items): array
    {
        $requests = array_map(static fn (array $i): array => ['method' => 'UPDATE', 'data' => array_filter([
            'id' => $i['retailer_id'],
            'item_group_id' => $i['group_id'],
            'title' => $i['title'],
            'description' => $i['description'] !== '' ? $i['description'] : $i['title'],
            'availability' => $i['quantity'] > 0 ? 'in stock' : 'out of stock',
            'inventory' => $i['quantity'],
            'condition' => 'new',
            'price' => $i['price'].' '.$i['currency'],
            'link' => $i['link'],
            'image_link' => $i['image_link'],
        ], static fn ($v): bool => $v !== null)], $items);

        $response = $this->call($account, 'post', $account->account_reference.'/items_batch', [
            'item_type' => 'PRODUCT_ITEM',
            'allow_upsert' => true,
            'requests' => json_encode($requests),
        ]);

        // Item-level rejections the batch reports at once; others arrive later in Commerce Manager.
        $rejected = [];

        foreach ((array) $response->json('validation_status', []) as $status) {
            $rejected[(string) ($status['retailer_id'] ?? '')] = (string) ($status['errors'][0]['message'] ?? 'Rejected by the channel');
        }

        $results = [];

        foreach ($items as $item) {
            $results[$item['retailer_id']] = ['external_id' => $item['retailer_id'], 'rejection' => $rejected[$item['retailer_id']] ?? null];
        }

        return $results;
    }

    public function remove(SocialCommerceAccount $account, array $externalIds): void
    {
        if ($externalIds === []) {
            return;
        }

        $this->call($account, 'post', $account->account_reference.'/items_batch', [
            'item_type' => 'PRODUCT_ITEM',
            'requests' => json_encode(array_map(static fn (string $id): array => ['method' => 'DELETE', 'data' => ['id' => $id]], $externalIds)),
        ]);
    }

    public function orders(SocialCommerceAccount $account, CarbonImmutable $since): array
    {
        if ($account->channel !== SocialCommerceAccount::FACEBOOK_SHOP || $account->order_account_reference === null) {
            return [];
        }

        $orders = [];
        $after = null;

        for ($page = 0; $page < 20; $page++) {
            $response = $this->call($account, 'get', $account->order_account_reference.'/commerce_orders', array_filter([
                'updated_after' => $since->getTimestamp(),
                'fields' => 'id,order_status,created,buyer_details,shipping_address,estimated_payment_details,items{retailer_id,quantity,price_per_unit,tax_details}',
                'limit' => 50,
                'after' => $after,
            ]));

            foreach ((array) $response->json('data', []) as $order) {
                $orders[] = $this->normalise($order);
            }

            $after = $response->json('paging.cursors.after');

            if ($after === null || $response->json('paging.next') === null) {
                break;
            }
        }

        return $orders;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function normalise(array $order): array
    {
        $payment = (array) ($order['estimated_payment_details'] ?? []);
        $address = (array) ($order['shipping_address'] ?? []);
        $state = (string) ($order['order_status']['state'] ?? '');

        return [
            'id' => (string) $order['id'],
            'currency' => (string) ($payment['total_amount']['currency'] ?? ''),
            'email' => $order['buyer_details']['email'] ?? null,
            'name' => $order['buyer_details']['name'] ?? null,
            'phone' => null,
            'address' => $address === [] ? null : [
                'name' => $address['name'] ?? null, 'line1' => $address['street1'] ?? '', 'line2' => $address['street2'] ?? null,
                'city' => $address['city'] ?? null, 'postal_code' => $address['postal_code'] ?? null,
                'country' => $address['country'] ?? null, 'state' => $address['state'] ?? null,
            ],
            'lines' => array_map(static fn (array $i): array => [
                'retailer_id' => (string) $i['retailer_id'],
                'sku' => null,
                'quantity' => (string) $i['quantity'],
                'unit_price' => (string) ($i['price_per_unit']['amount'] ?? '0'),
                'tax' => (string) ($i['tax_details']['estimated_tax']['amount'] ?? '0'),
            ], (array) ($order['items']['data'] ?? [])),
            'shipping' => (string) ($payment['shipping']['amount'] ?? '0'),
            'shipping_tax' => '0',
            'total' => (string) ($payment['total_amount']['amount'] ?? '0'),
            // Meta's native checkout charges the buyer before the order reaches the seller.
            'paid' => in_array($state, ['CREATED', 'IN_PROGRESS', 'COMPLETED'], true),
            'cancelled' => $state === 'CANCELLED',
            'created_at' => $order['created'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function call(SocialCommerceAccount $account, string $method, string $path, array $data): Response
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('integrations.social_commerce.meta_graph_url'), '/').'/')
                ->withToken($account->access_token)
                ->connectTimeout((int) config('integrations.http.connect_timeout', 10))
                ->timeout((int) config('integrations.http.timeout', 30))
                ->withoutRedirecting()->acceptJson()
                // References are validated to [A-Za-z0-9_.-] when the account is connected.
                ->{$method}($path, $data);
        } catch (ConnectionException) {
            throw new SocialCommerceException('Meta could not be reached.');
        }

        if ($response->successful()) {
            return $response;
        }

        // Code 190: the token expired or was revoked.
        if ((int) $response->json('error.code') === 190) {
            throw new SocialCommerceException('The Meta access token has expired or been revoked. Reconnect the account.');
        }

        throw new SocialCommerceException('Meta error: '.mb_substr((string) ($response->json('error.message') ?? 'HTTP '.$response->status()), 0, 250));
    }
}
