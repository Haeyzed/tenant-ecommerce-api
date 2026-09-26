<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Services\NotificationPreferenceService;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The authenticated actor's own channel preferences (spec §17.7).
 */
final class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function index(Request $request): JsonResponse
    {
        return APIResponse::success($this->list($this->actor($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_key' => ['present', 'nullable', 'string', 'max:128'],
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'enabled' => ['required', 'boolean'],
        ]);

        $actor = $this->actor($request);
        $this->preferences->setPreference($actor, $validated['template_key'], $validated['channel'], (bool) $validated['enabled']);

        return APIResponse::success($this->list($actor), 'Preference saved');
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'channel' => ['sometimes', 'nullable', Rule::enum(NotificationChannel::class)],
        ]);

        $actor = $this->actor($request);
        $this->preferences->resetToDefault($actor, $validated['template_key'] ?? null, $validated['channel'] ?? null);

        return APIResponse::success($this->list($actor), 'Preferences reset');
    }

    /**
     * @return list<array{template_key: string|null, channel: string, enabled: bool}>
     */
    private function list(Model $actor): array
    {
        return $this->preferences->getPreferences($actor)
            ->map(static fn (NotificationPreference $p): array => ['template_key' => $p->template_key, 'channel' => $p->channel, 'enabled' => $p->enabled])
            ->values()
            ->all();
    }

    private function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
