<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\SupportPresenter;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportMessageAttachment;
use App\Modules\Support\Services\SupportMessageService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use App\Shared\Media\PrivateFileResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff replies, internal notes and read receipts (spec §59.4).
 */
final class MessageController extends Controller
{
    public function __construct(
        private readonly SupportMessageService $messages,
        private readonly SupportPresenter $presenter,
    ) {}

    /**
     * Multipart: body, attachments[]?
     */
    public function store(Request $request, SupportConversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $message = $this->messages->sendMessage($conversation, [
            'sender_type' => SupportMessage::STAFF,
            'sender_id' => $user->id,
            'body' => (string) $request->input('body', ''),
        ], array_values(array_filter((array) $request->file('attachments', []))));

        return APIResponse::created($this->presenter->message($message, true), 'Reply sent');
    }

    /**
     * Body: body. Staff only; never shown to the customer.
     */
    public function addNote(Request $request, SupportConversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::created($this->presenter->message($this->messages->addInternalNote($conversation, $user, (string) $request->input('body', '')), true), 'Note added');
    }

    /**
     * Body: message_id? (default: the latest). Marks the customer's messages up to it read.
     */
    public function markRead(Request $request, SupportConversation $conversation): JsonResponse
    {
        $id = $request->validate(['message_id' => ['sometimes', 'integer']])['message_id'] ?? null;
        $message = SupportMessage::query()->where('support_conversation_id', $conversation->id)
            ->when($id !== null, static fn ($q) => $q->whereKey((int) $id))->orderByDesc('id')->first();

        return APIResponse::success(['marked' => $message === null ? 0 : $this->messages->markRead($message, true)]);
    }

    public function attachment(SupportConversation $conversation, SupportMessageAttachment $attachment, PrivateFileResponder $files): Response
    {
        if ($attachment->message->support_conversation_id !== $conversation->id) {
            throw new NotFoundHttpException;
        }

        return $files->respond($attachment->getFirstMedia('attachment'));
    }
}
