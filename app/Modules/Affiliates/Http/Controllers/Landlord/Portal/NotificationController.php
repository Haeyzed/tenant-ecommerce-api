<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Portal;

use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The affiliate's in-app notifications (the "database" channel).
 */
final class NotificationController extends PortalController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        $page = $this->affiliate($request)->notifications()
            ->when($request->boolean('unread'), static fn ($q) => $q->whereNull('read_at'))
            ->paginate($this->perPage($request))
            ->through(static fn (DatabaseNotification $n): array => [
                'id' => $n->id,
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return APIResponse::success($page);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->affiliate($request)->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return APIResponse::success(null, 'Marked as read');
    }
}
