<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Storefront presentation (spec §13.5)
|--------------------------------------------------------------------------
|
| Theme and font identifiers the storefront frontend knows, and the
| storefront_settings key registry. Every key is public by definition: a
| secret must never be added here.
|
*/

$hex = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
$themes = ['default', 'minimal', 'bold', 'classic'];
$fonts = ['inter', 'roboto', 'open-sans', 'lato', 'montserrat', 'poppins', 'playfair-display', 'merriweather'];

return [

    'themes' => $themes,

    'fonts' => $fonts,

    'settings' => [
        'theme' => ['type' => 'string', 'default' => 'default', 'rules' => ['required', 'string', 'in:'.implode(',', $themes)]],
        'color_primary' => ['type' => 'string', 'default' => null, 'rules' => $hex],
        'color_secondary' => ['type' => 'string', 'default' => null, 'rules' => $hex],
        'color_accent' => ['type' => 'string', 'default' => null, 'rules' => $hex],
        'color_background' => ['type' => 'string', 'default' => null, 'rules' => $hex],
        'color_text' => ['type' => 'string', 'default' => null, 'rules' => $hex],
        'font_heading' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'in:'.implode(',', $fonts)]],
        'font_body' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'in:'.implode(',', $fonts)]],
        'social_links' => ['type' => 'json', 'default' => null, 'rules' => ['nullable', 'array:facebook,instagram,x,linkedin,youtube,tiktok,whatsapp']],
        'seo_title_template' => ['type' => 'string', 'default' => '{page} - {store_name}', 'rules' => ['required', 'string', 'max:120']],
        'seo_default_description' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:320']],
        'seo_share_image_media_id' => ['type' => 'int', 'default' => null, 'rules' => ['nullable', 'integer']],
        'robots_indexing_enabled' => ['type' => 'bool', 'default' => true, 'rules' => ['required', 'boolean']],
        'analytics_measurement_id' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'regex:/^(G|UA)-[A-Z0-9\-]{4,20}$/']],
        'facebook_pixel_id' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/']],
        'google_tag_manager_id' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'regex:/^GTM-[A-Z0-9]{4,12}$/']],
        'cookie_consent_enabled' => ['type' => 'bool', 'default' => true, 'rules' => ['required', 'boolean']],
        'cookie_consent_text' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string', 'max:500']],
        'checkout_phone_required' => ['type' => 'bool', 'default' => false, 'rules' => ['required', 'boolean']],
        'checkout_order_notes_enabled' => ['type' => 'bool', 'default' => true, 'rules' => ['required', 'boolean']],
    ],

];
