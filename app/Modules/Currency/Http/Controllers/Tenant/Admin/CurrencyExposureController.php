<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Currency\Services\CurrencyService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * Outstanding balances by currency with their base value (spec §48.3).
 */
final class CurrencyExposureController extends Controller
{
    public function __construct(private readonly CurrencyService $currencies) {}

    public function show(): JsonResponse
    {
        return APIResponse::success($this->currencies->getCurrencyExposure());
    }
}
