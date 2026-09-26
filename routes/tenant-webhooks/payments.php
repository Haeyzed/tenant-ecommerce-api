<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\TenantPaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Storefront payment webhooks (spec §15.6): the tenant comes from the path;
| the signature is verified with the tenant's own credentials.
*/

Route::middleware(['tenant.webhooks', 'verify.webhook'])
    ->post('webhooks/{tenant}/{provider}/{mode}', [TenantPaymentWebhookController::class, 'handle'])
    ->where(['provider' => 'paystack|flutterwave|stripe', 'mode' => 'test|live'])
    ->name('tenant.payments.webhook');
