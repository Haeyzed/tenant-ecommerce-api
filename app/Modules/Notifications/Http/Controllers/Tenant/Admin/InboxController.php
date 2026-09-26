<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;

/**
 * The authenticated actor's own in-app notifications (spec §17.7).
 */
final class InboxController extends Controller
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
}
