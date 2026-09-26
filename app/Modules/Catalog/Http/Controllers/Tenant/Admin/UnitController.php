<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Modules\Catalog\Services\CatalogReferenceService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Units of measure (spec §29.8).
 */
final class UnitController extends Controller
{
    private const array FIELDS = ['id', 'name', 'short_code', 'allows_decimal'];

    public function __construct(private readonly CatalogReferenceService $references) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->references->listUnits()->map(static fn (UnitOfMeasure $u): array => $u->only(self::FIELDS))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->references->createUnit($request->all())->only(self::FIELDS), 'Unit created');
    }

    public function update(Request $request, UnitOfMeasure $unit): JsonResponse
    {
        return APIResponse::success($this->references->updateUnit($unit, $request->all())->only(self::FIELDS), 'Unit updated');
    }

    public function destroy(UnitOfMeasure $unit): JsonResponse
    {
        $this->references->deleteUnit($unit);

        return APIResponse::noContent('Unit deleted');
    }
}
