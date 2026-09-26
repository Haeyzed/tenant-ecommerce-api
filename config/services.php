<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Platform messaging transports (spec §16). Tenants may configure their
    | own SMS gateways and WhatsApp number; these are the platform's own,
    | used for landlord messages and as the tenant fallback where allowed.
    */

    'sms' => [
        // termii | africas_talking | empty = no platform SMS
        'platform_provider' => env('PLATFORM_SMS_PROVIDER'),
        'timeout' => (int) env('SMS_HTTP_TIMEOUT', 10),
    ],

    'termii' => [
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
        'api_key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID'),
    ],

    'africas_talking' => [
        'base_url' => env('AFRICASTALKING_BASE_URL', 'https://api.africastalking.com'),
        'username' => env('AFRICASTALKING_USERNAME'),
        'api_key' => env('AFRICASTALKING_API_KEY'),
        'sender_id' => env('AFRICASTALKING_SENDER_ID'),
    ],

    'whatsapp' => [
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 10),
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'client_email' => env('FCM_CLIENT_EMAIL'),
        // PEM private key of the service account; "\n" escapes are accepted.
        'private_key' => env('FCM_PRIVATE_KEY'),
        'token_uri' => env('FCM_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'base_url' => env('FCM_BASE_URL', 'https://fcm.googleapis.com'),
        'timeout' => (int) env('FCM_HTTP_TIMEOUT', 10),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
