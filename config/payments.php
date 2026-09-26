<?php

declare(strict_types=1);
use App\Shared\Payments\Gateways\FlutterwaveGateway;
use App\Shared\Payments\Gateways\PaystackGateway;
use App\Shared\Payments\Gateways\StripeGateway;

/*
|--------------------------------------------------------------------------
| Payment providers (spec §15.3)
|--------------------------------------------------------------------------
|
| Driver defaults and tenant-side currency support. Never credentials:
| platform credentials live in platform_payment_gateways and tenant
| credentials in each tenant's tenant_payment_settings (both encrypted).
|
*/

return [

    'providers' => [
        'flutterwave' => [
            'driver' => FlutterwaveGateway::class,
            'base_url' => 'https://api.flutterwave.com/v3',
            'currencies' => ['NGN', 'GHS', 'KES', 'ZAR', 'UGX', 'TZS', 'RWF', 'XOF', 'XAF', 'EGP', 'MAD', 'USD', 'EUR', 'GBP'],
        ],
        'paystack' => [
            'driver' => PaystackGateway::class,
            'base_url' => 'https://api.paystack.co',
            'currencies' => ['NGN', 'GHS', 'ZAR', 'KES', 'XOF', 'EGP', 'USD'],
        ],
        'stripe' => [
            'driver' => StripeGateway::class,
            'base_url' => 'https://api.stripe.com',
            'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'JPY', 'SGD', 'HKD', 'ZAR'],
        ],
    ],

    'modes' => ['test', 'live'],

];
