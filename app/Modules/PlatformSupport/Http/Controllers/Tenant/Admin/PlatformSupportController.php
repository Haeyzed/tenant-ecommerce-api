<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\PlatformSupport\Models\PlatformSupportMessage;
use App\Modules\PlatformSupport\Services\PlatformSupportConversationService;
use App\Modules\PlatformSupport\Services\PlatformSupportMessageService;
use App\Modules\PlatformSupport\Support\ConversationPresenter;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant staff asking the platform for help (spec §21.4). Any staff user;
 * only the current tenant's conversations are visible.
 */
final class PlatformSupportController extends Controller
{
    public function __construct(
        private readonly PlatformSupportConversationService $conversations,
        private readonly PlatformSupportMessageService $messages,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->conversations->getForTenant($this->tenant())
            ->map(static fn (PlatformSupportConversation $c): array => ConversationPresenter::summary($c, false))
            ->values());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $conversation = $this->conversations->openConversation(
            $this->tenant(),
            ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            $request->only(['subject', 'category', 'body']),
            array_values((array) $request->file('attachments', [])),
        );

        return APIResponse::created(ConversationPresenter::detail($conversation, false));
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $row = $this->own($conversation);
        $last = $row->messages()->where('is_internal_note', false)->latest('id')->first();

        if ($last !== null) {
            $this->messages->markRead($last, PlatformSupportMessage::TENANT);
        }

        return APIResponse::success(ConversationPresenter::detail($row, false));
    }

    public function sendMessage(Request $request, int $conversation): JsonResponse
    {
        $row = $this->own($conversation);
        $user = $this->user($request);

        $message = $this->messages->sendMessage($row, [
            'sender_type' => PlatformSupportMessage::TENANT,
            'sender_id' => $user->id,
            'sender_label' => $user->name,
            'body' => (string) $request->input('body', ''),
        ], array_values((array) $request->file('attachments', [])));

        return APIResponse::created(['id' => $message->id, 'conversation' => ConversationPresenter::summary($row->refresh(), false)], 'Message sent');
    }

    private function own(int $id): PlatformSupportConversation
    {
        return PlatformSupportConversation::query()->where('tenant_id', $this->tenant()->getTenantKey())->findOrFail($id);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
