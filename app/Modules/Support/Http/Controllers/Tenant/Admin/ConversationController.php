<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\SupportPresenter;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Services\SupportConversationService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The support inbox (spec §59.4).
 */
final class ConversationController extends Controller
{
    public function __construct(
        private readonly SupportConversationService $conversations,
        private readonly SupportPresenter $presenter,
    ) {}

    /**
     * Query: status?, channel?, assigned_to? (me | unassigned | a user id), customer_id?
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(SupportConversation::STATUSES)],
            'channel' => ['sometimes', Rule::in(SupportConversation::CHANNELS)],
            'assigned_to' => ['sometimes', 'string', 'regex:/^(me|unassigned|\d+)$/'],
            'customer_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->conversations->listConversations($filters, $user)
            ->through(fn (SupportConversation $c): array => $this->presenter->conversation($c, true)));
    }

    public function show(SupportConversation $conversation): JsonResponse
    {
        return APIResponse::success($this->presenter->conversation($conversation, true, true));
    }

    /**
     * Body: status?, priority? (tickets), assigned_to_user_id? (null unassigns).
     */
    public function update(Request $request, SupportConversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string'],
            'priority' => ['sometimes', 'string'],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        DB::connection('tenant')->transaction(function () use ($conversation, $validated): void {
            if (array_key_exists('assigned_to_user_id', $validated)) {
                $agent = $validated['assigned_to_user_id'] === null ? null : User::query()->findOrFail((int) $validated['assigned_to_user_id']);
                $this->conversations->assignToAgent($conversation, $agent);
            }

            if (isset($validated['priority'])) {
                $this->conversations->updatePriority($conversation, $validated['priority']);
            }

            if (isset($validated['status'])) {
                $this->conversations->updateStatus($conversation, $validated['status']);
            }
        });

        return APIResponse::success($this->presenter->conversation($conversation->refresh(), true), 'Conversation updated');
    }
}
