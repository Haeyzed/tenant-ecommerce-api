<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform settings by group (spec §13.2, §13.8).
 */
final class PlatformSettingsController extends Controller
{
    public function __construct(private readonly PlatformSettingsService $settings) {}

    public function index(): JsonResponse
    {
        $groups = [];

        foreach ($this->settings->groups() as $group) {
            $groups[$group] = $this->masked($this->settings->group($group));
        }

        return APIResponse::success($groups);
    }

    public function show(string $group): JsonResponse
    {
        return APIResponse::success($this->masked($this->settings->group($group)));
    }

    public function update(Request $request, string $group): JsonResponse
    {
        $validated = $request->validate([
            'values' => ['required', 'array', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        /** @var PlatformUser $user */
        $user = $request->user();
        $this->settings->updateGroup($group, $validated['values'], $user, $validated['reason'] ?? null);

        return APIResponse::success($this->masked($this->settings->group($group)), 'Settings updated');
    }

    /**
     * Encrypted values are write-only.
     *
     * @param  array<string, array<string, mixed>>  $group
     * @return array<string, array<string, mixed>>
     */
    private function masked(array $group): array
    {
        foreach ($group as $key => $entry) {
            if ($entry['type'] === 'encrypted_json') {
                $group[$key]['value'] = null;
                $group[$key]['has_value'] = $entry['value'] !== null;
            }
        }

        return $group;
    }
}
