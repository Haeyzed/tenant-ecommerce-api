<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Concerns;

use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;

/**
 * The authenticated actor's own in-app notifications (spec §17.7): staff
 * and customers in a tenant, platform users on the landlord (BG-08).
 */
trait ServesNotificationInbox
{
    public function index(Request $request): JsonResponse
    {
        /** @var Model&Notifiable $actor */
        $actor = $request->user();

        $page = $actor->notifications()
            ->when($request->boolean('unread'), static fn ($q) => $q->whereNull('read_at'))
            ->paginate(min(100, max(1, $request->integer('per_page', 25))))
            ->through(static fn (DatabaseNotification $n): array => [
                'id' => $n->id,
                'key' => $n->type,
                'subject' => $n->data['subject'] ?? null,
                'body' => $n->data['body'] ?? null,
                'data' => $n->data['data'] ?? [],
                // "platform" for messages from the platform (UD-10), else "store".
                'source' => $n->data['source'] ?? 'store',
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return APIResponse::success($page, 'OK', ['unread_count' => $actor->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        /** @var Model&Notifiable $actor */
        $actor = $request->user();

        $notification = $actor->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return APIResponse::success(['id' => $notification->id, 'read_at' => $notification->read_at?->toIso8601String()], 'Marked as read');
    }

    /**
     * Marks every unread notification of the actor read (BG-09).
     */
    public function markAllRead(Request $request): JsonResponse
    {
        /** @var Model&Notifiable $actor */
        $actor = $request->user();

        return APIResponse::success(['marked' => $actor->unreadNotifications()->update(['read_at' => now()])], 'Marked all as read');
    }
}
