<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\PlatformSupport\Models\PlatformSupportMessage;
use App\Modules\PlatformSupport\Services\PlatformSupportConversationService;
use App\Modules\PlatformSupport\Services\PlatformSupportMessageService;
use App\Modules\PlatformSupport\Support\ConversationPresenter;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The platform helpdesk inbox (spec §21.4).
 */
final class ConversationController extends Controller
{
    public function __construct(
        private readonly PlatformSupportConversationService $conversations,
        private readonly PlatformSupportMessageService $messages,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tenant' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(PlatformSupportConversation::STATUSES)],
            'category' => ['sometimes', Rule::in(PlatformSupportConversation::CATEGORIES)],
            'assignee' => ['sometimes', 'string', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->conversations->listConversations($filters)
            ->through(static fn (PlatformSupportConversation $c): array => ConversationPresenter::summary($c, true)));
    }

    public function show(PlatformSupportConversation $conversation): JsonResponse
    {
        $last = $conversation->messages()->where('sender_type', PlatformSupportMessage::TENANT)->latest('id')->first();

        if ($last !== null) {
            $this->messages->markRead($last, PlatformSupportMessage::PLATFORM_USER);
        }

        return APIResponse::success(ConversationPresenter::detail($conversation->load(['tenant:id,name', 'assignee:id,name']), true));
    }

    /**
     * Status, priority and assignment.
     */
    public function update(Request $request, PlatformSupportConversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(PlatformSupportConversation::STATUSES)],
            'priority' => ['sometimes', Rule::in(PlatformSupportConversation::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.platform_users', 'id')],
        ]);

        if (array_key_exists('assigned_to', $validated)) {
            $staff = $validated['assigned_to'] === null ? null : PlatformUser::query()->findOrFail($validated['assigned_to']);
            $this->conversations->assignToStaff($conversation, $staff, $this->actor($request));
        }

        if (isset($validated['status'])) {
            $this->conversations->updateStatus($conversation, $validated['status']);
        }

        if (isset($validated['priority'])) {
            $this->conversations->updatePriority($conversation, $validated['priority']);
        }

        return APIResponse::success(ConversationPresenter::summary($conversation->refresh()->load(['tenant:id,name', 'assignee:id,name']), true), 'Conversation updated');
    }

    public function sendMessage(Request $request, PlatformSupportConversation $conversation): JsonResponse
    {
        $actor = $this->actor($request);

        $message = $this->messages->sendMessage($conversation, [
            'sender_type' => PlatformSupportMessage::PLATFORM_USER,
            'sender_id' => $actor->id,
            'sender_label' => $actor->name,
            'body' => (string) $request->input('body', ''),
        ], array_values((array) $request->file('attachments', [])));

        return APIResponse::created(['id' => $message->id], 'Reply sent');
    }

    public function addNote(Request $request, PlatformSupportConversation $conversation): JsonResponse
    {
        $note = $this->messages->addInternalNote($conversation, $this->actor($request), (string) $request->input('body', ''));

        return APIResponse::created(['id' => $note->id], 'Note added');
    }

    private function actor(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
