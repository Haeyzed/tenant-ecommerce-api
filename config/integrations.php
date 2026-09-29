<?php

declare(strict_types=1);

/*
| Store integrations (spec §68 WooCommerce, §69 social commerce). The
| tenant supplies its own credentials; these are platform-side settings.
*/

return [

    /*
     * Outbound calls go only to public HTTPS hosts (no private, loopback or
     * reserved addresses), so a tenant-supplied URL cannot reach internal
     * services. Only for local development against a store on your own
     * machine or network.
     */
    'allow_private_hosts' => (bool) env('INTEGRATIONS_ALLOW_PRIVATE_HOSTS', false),

    'http' => [
        'connect_timeout' => 10,
        'timeout' => 30,
    ],

    'woocommerce' => [
        // Orders imported on the first run: those created in the last N days.
        'initial_order_import_days' => 30,
        'page_size' => 100,
    ],

    'social_commerce' => [
        // Facebook Shop, Instagram and the WhatsApp catalogue use the Meta Graph API.
        'meta_graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
        // TikTok Shop signs every call with the platform's app key and secret (UD-23).
        'tiktok_api_url' => env('TIKTOK_SHOP_API_URL', 'https://open-api.tiktokglobalshop.com'),
        'tiktok_app_key' => env('TIKTOK_SHOP_APP_KEY'),
        'tiktok_app_secret' => env('TIKTOK_SHOP_APP_SECRET'),
        'initial_order_import_days' => 7,
    ],

];
