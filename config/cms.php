<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CMS registries (spec §24)
|--------------------------------------------------------------------------
|
| Shared by the landlord website and every tenant storefront. The same
| services read this in both scopes (CmsScope::current()).
|
*/

$url = ['nullable', 'string', 'max:2048', 'regex:/^(https?:\/\/|\/|mailto:|tel:)/i'];
$both = ['landlord', 'tenant'];

return [

    /*
     * Section types (§24.2): the scopes each may be used in and the
     * validation rules of its settings. Dynamic sections are filled by the
     * frontend from existing public endpoints.
     */
    'section_types' => [
        'rich_text' => ['scopes' => $both, 'rules' => ['body' => ['required', 'string', 'max:100000']]],
        'hero' => ['scopes' => $both, 'rules' => [
            'heading' => ['required', 'string', 'max:160'], 'subheading' => ['nullable', 'string', 'max:320'],
            'media_id' => ['nullable', 'integer'], 'cta_label' => ['nullable', 'string', 'max:60'], 'cta_url' => $url,
        ]],
        'image_with_text' => ['scopes' => $both, 'rules' => [
            'media_id' => ['nullable', 'integer'], 'heading' => ['nullable', 'string', 'max:160'], 'body' => ['nullable', 'string', 'max:20000'],
            'image_position' => ['required', 'in:left,right'],
        ]],
        'features_grid' => ['scopes' => $both, 'rules' => [
            'items' => ['required', 'array', 'min:1', 'max:12'], 'items.*.title' => ['required', 'string', 'max:120'],
            'items.*.text' => ['nullable', 'string', 'max:500'], 'items.*.icon' => ['nullable', 'string', 'max:64'],
        ]],
        'call_to_action' => ['scopes' => $both, 'rules' => [
            'heading' => ['required', 'string', 'max:160'], 'body' => ['nullable', 'string', 'max:2000'],
            'cta_label' => ['required', 'string', 'max:60'], 'cta_url' => ['required', ...array_slice($url, 1)],
        ]],
        'testimonials' => ['scopes' => $both, 'rules' => ['featured_only' => ['sometimes', 'boolean'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:12']]],
        'faq' => ['scopes' => $both, 'rules' => ['faq_category_id' => ['nullable', 'integer']]],
        'contact_form' => ['scopes' => $both, 'rules' => ['heading' => ['nullable', 'string', 'max:160'], 'success_message' => ['nullable', 'string', 'max:500']]],
        'pricing_table' => ['scopes' => ['landlord'], 'rules' => ['default_interval' => ['required', 'in:monthly,yearly'], 'currency' => ['nullable', 'string', 'size:3']]],
        'plan_comparison' => ['scopes' => ['landlord'], 'rules' => []],
        'legal_document' => ['scopes' => ['landlord'], 'rules' => ['document_type' => ['required', 'in:terms_of_service,privacy_policy,data_processing_agreement,acceptable_use_policy,affiliate_agreement']]],
        'product_carousel' => ['scopes' => ['tenant'], 'rules' => [
            'source' => ['required', 'in:featured,newest,best_selling,category'], 'category_id' => ['required_if:source,category', 'nullable', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ]],
        'category_grid' => ['scopes' => ['tenant'], 'rules' => ['category_ids' => ['required', 'array', 'min:1', 'max:12'], 'category_ids.*' => ['integer']]],
        'brand_strip' => ['scopes' => ['tenant'], 'rules' => ['brand_ids' => ['required', 'array', 'min:1', 'max:24'], 'brand_ids.*' => ['integer']]],
    ],

    /*
     * Menu link types (§24.3). category, brand and product exist in the
     * tenant scope and are accepted once the catalogue registers a link
     * resolver for them (CmsLinkResolver).
     */
    'link_types' => [
        'page' => $both,
        'url' => $both,
        'blog' => $both,
        'category' => ['tenant'],
        'brand' => ['tenant'],
        'product' => ['tenant'],
    ],

    'menu_max_depth' => 2,

    // Image banner positions (§24.5).
    'banner_positions' => ['homepage-hero', 'homepage-secondary', 'homepage-footer', 'category-top', 'sidebar'],

    'announcement_bar_max' => 3,

    // Public URL paths the frontends serve, used by menus and sitemaps.
    'paths' => [
        'home' => '/',
        'page' => '/pages/{slug}',
        'blog' => '/blog',
        'blog_post' => '/blog/{slug}',
    ],

    'contact_retention_months' => 24,

    /*
     * Seeded structure (§24.6), never legal or marketing text. Insert-only
     * by system_key and menu key; tenants' changes are never overwritten.
     */
    'defaults' => [
        'tenant' => [
            'pages' => [
                ['system_key' => 'home', 'title' => 'Home', 'slug' => 'home', 'status' => 'published', 'is_homepage' => true,
                    'sections' => [['section_type' => 'product_carousel', 'settings' => ['source' => 'newest', 'limit' => 12]]]],
                ['system_key' => 'privacy_policy', 'title' => 'Privacy policy', 'slug' => 'privacy-policy'],
                ['system_key' => 'terms', 'title' => 'Terms and conditions', 'slug' => 'terms'],
                ['system_key' => 'refund_policy', 'title' => 'Refund policy', 'slug' => 'refund-policy'],
                ['system_key' => 'shipping_policy', 'title' => 'Shipping policy', 'slug' => 'shipping-policy'],
                ['system_key' => 'cookie_policy', 'title' => 'Cookie policy', 'slug' => 'cookie-policy'],
            ],
            'menus' => [['key' => 'header', 'name' => 'Header'], ['key' => 'footer', 'name' => 'Footer']],
        ],
        'landlord' => [
            'pages' => [
                ['system_key' => 'home', 'title' => 'Home', 'slug' => 'home', 'is_homepage' => true],
                ['system_key' => 'pricing', 'title' => 'Pricing', 'slug' => 'pricing', 'sections' => [
                    ['section_type' => 'pricing_table', 'settings' => ['default_interval' => 'monthly']],
                    ['section_type' => 'plan_comparison', 'settings' => []],
                ]],
                ['system_key' => 'contact', 'title' => 'Contact', 'slug' => 'contact', 'sections' => [['section_type' => 'contact_form', 'settings' => []]]],
                ['system_key' => 'terms', 'title' => 'Terms of service', 'slug' => 'terms', 'sections' => [
                    ['section_type' => 'legal_document', 'settings' => ['document_type' => 'terms_of_service']],
                ]],
                ['system_key' => 'privacy_policy', 'title' => 'Privacy policy', 'slug' => 'privacy-policy', 'sections' => [
                    ['section_type' => 'legal_document', 'settings' => ['document_type' => 'privacy_policy']],
                ]],
            ],
            'menus' => [['key' => 'header', 'name' => 'Header'], ['key' => 'footer', 'name' => 'Footer']],
        ],
    ],

];
