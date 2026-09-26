<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Http\Resources\CustomFieldDefinitionResource;
use App\Modules\CustomFields\Models\CustomFieldDefinition;
use App\Modules\CustomFields\Services\CustomFieldDefinitionService;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Custom field definitions (spec §23.7). Module gating is applied by the
 * service; max_custom_fields by the usage.limit route middleware.
 */
final class CustomFieldController extends Controller
{
    public function __construct(private readonly CustomFieldDefinitionService $definitions) {}

    public function entities(CustomFieldEntityRegistry $registry): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return APIResponse::success($registry->all($tenant));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entity_type' => ['required', 'string', 'max:48'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $list = $this->definitions->list($filters['entity_type'], array_key_exists('is_active', $filters) ? $request->boolean('is_active') : null);

        return APIResponse::success(CustomFieldDefinitionResource::collection($list));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(new CustomFieldDefinitionResource($this->definitions->create($request->all())), 'Custom field created');
    }

    public function show(CustomFieldDefinition $field): JsonResponse
    {
        return APIResponse::success((new CustomFieldDefinitionResource($field))->withValueCount($this->definitions->valueCount($field)));
    }

    public function update(Request $request, CustomFieldDefinition $field): JsonResponse
    {
        return APIResponse::success(new CustomFieldDefinitionResource($this->definitions->update($field, $request->all())), 'Custom field updated');
    }

    public function deactivate(CustomFieldDefinition $field): JsonResponse
    {
        return APIResponse::success(new CustomFieldDefinitionResource($this->definitions->deactivate($field)), 'Custom field deactivated');
    }

    public function reactivate(CustomFieldDefinition $field): JsonResponse
    {
        return APIResponse::success(new CustomFieldDefinitionResource($this->definitions->reactivate($field)), 'Custom field reactivated');
    }

    public function destroy(CustomFieldDefinition $field): JsonResponse
    {
        $this->definitions->delete($field);

        return APIResponse::noContent('Custom field deleted');
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'max:48'],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
        ]);

        $this->definitions->reorder($validated['entity_type'], $validated['ordered_ids']);

        return APIResponse::success($this->definitions->list($validated['entity_type'])->pluck('id'), 'Custom fields reordered');
    }
}
