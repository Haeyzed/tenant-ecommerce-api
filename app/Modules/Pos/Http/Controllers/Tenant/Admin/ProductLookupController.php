<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Services\PosSaleService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scan or search (spec §51.3 step 2).
 */
final class ProductLookupController extends Controller
{
    public function __construct(private readonly PosSaleService $sales) {}

    /**
     * Query: barcode | q, register_id? (price and stock at its warehouse).
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => ['required_without:q', 'nullable', 'string', 'max:64'],
            'q' => ['required_without:barcode', 'nullable', 'string', 'max:120'],
            'register_id' => ['sometimes', 'integer'],
        ]);

        $register = isset($validated['register_id']) ? PosRegister::query()->findOrFail($validated['register_id']) : null;

        return APIResponse::success($this->sales->lookupProducts($validated['barcode'] ?? null, $validated['q'] ?? null, $register));
    }
}
