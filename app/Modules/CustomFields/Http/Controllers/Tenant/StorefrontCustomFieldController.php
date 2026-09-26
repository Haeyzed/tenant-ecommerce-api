<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\CustomFields\Http\Resources\CustomFieldDefinitionResource;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Active public definitions, so checkout and registration forms can render
 * them (spec §23.7).
 */
final class StorefrontCustomFieldController extends Controller
{
    public const array PUBLIC_ENTITIES = ['customer', 'order', 'product'];

    public function index(Request $request, CustomFieldService $values, CustomFieldEntityRegistry $registry): JsonResponse
    {
        $type = $request->validate(['entity_type' => ['required', Rule::in(self::PUBLIC_ENTITIES)]])['entity_type'];

        /** @var Tenant $tenant */
        $tenant = tenant();

        if (! $registry->has($type) || ! $registry->valuesVisible($tenant, $type)) {
            return APIResponse::success([]);
        }

        $definitions = array_values($values->definitions($type, CustomFieldService::PUBLIC));

        return APIResponse::success(array_map(static fn ($d): array => (new CustomFieldDefinitionResource($d))->forPublic()->resolve($request), $definitions));
    }
}
