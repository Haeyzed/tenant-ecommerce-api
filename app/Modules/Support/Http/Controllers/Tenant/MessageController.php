<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\Controllers\Tenant\Concerns\ResolvesSupportActor;
use App\Modules\Support\Http\SupportPresenter;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportMessageAttachment;
use App\Modules\Support\Services\SupportMessageService;
use App\Shared\Http\APIResponse;
use App\Shared\Media\PrivateFileResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's or guest's messages (spec §59.4). The attachment download
 * is an addition: files are never served from a public URL.
 */
final class MessageController extends Controller
{
    use ResolvesSupportActor;

    public function __construct(
        private readonly SupportMessageService $messages,
        private readonly SupportPresenter $presenter,
    ) {}

    /**
     * Multipart: body, attachments[]? (up to five files).
     */
    public function store(Request $request, SupportConversation $conversation): JsonResponse
    {
        $conversation = $this->own($request, $conversation);
        $customer = $this->customer($request);
        $message = $this->messages->sendMessage($conversation, [
            'sender_type' => $customer === null ? SupportMessage::GUEST : SupportMessage::CUSTOMER,
            'sender_id' => $customer?->id,
            'body' => (string) $request->input('body', ''),
        ], array_values(array_filter((array) $request->file('attachments', []))));

        return APIResponse::created($this->presenter->message($message, false), 'Message sent');
    }

    /**
     * Body: message_id? (default: the latest). Marks the store's replies up to it read.
     */
    public function markRead(Request $request, SupportConversation $conversation): JsonResponse
    {
        $conversation = $this->own($request, $conversation);
        $message = $this->target($request, $conversation);
        $count = $message === null ? 0 : $this->messages->markRead($message, false);

        return APIResponse::success(['marked' => $count]);
    }

    public function attachment(Request $request, SupportConversation $conversation, SupportMessageAttachment $attachment, PrivateFileResponder $files): Response
    {
        $conversation = $this->own($request, $conversation);
        $message = $attachment->message;

        if ($message->support_conversation_id !== $conversation->id || $message->is_internal_note) {
            throw new NotFoundHttpException;
        }

        return $files->respond($attachment->getFirstMedia('attachment'));
    }

    private function target(Request $request, SupportConversation $conversation): ?SupportMessage
    {
        $id = $request->validate(['message_id' => ['sometimes', 'integer']])['message_id'] ?? null;

        return SupportMessage::query()->where('support_conversation_id', $conversation->id)->where('is_internal_note', false)
            ->when($id !== null, static fn ($q) => $q->whereKey((int) $id))->orderByDesc('id')->first();
    }
}
