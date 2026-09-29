<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\Controllers\Tenant\Concerns\ResolvesSupportActor;
use App\Modules\Support\Http\SupportPresenter;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Services\SupportConversationService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A customer's or guest's support conversations (spec §59.4). Internal
 * notes are never shown. A guest without a token is given one, returned as
 * guest_token (the same token as the guest cart, §38.2).
 */
final class ConversationController extends Controller
{
    use ResolvesSupportActor;

    public function __construct(
        private readonly SupportConversationService $conversations,
        private readonly SupportPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $token = ResolveGuestToken::from($request);
        $list = $customer !== null ? $this->conversations->getForCustomer($customer) : ($token === null ? collect() : $this->conversations->getForGuest($token));

        return APIResponse::success($list->map(fn (SupportConversation $c): array => $this->presenter->conversation($c, false))->values()->all());
    }

    /**
     * Body: channel (ticket | chat), subject (tickets), body (the first
     * message; optional for chat), guest_name and guest_email (guest tickets).
     */
    public function store(Request $request): JsonResponse
    {
        $channel = $request->validate(['channel' => ['required', Rule::in(SupportConversation::CHANNELS)]])['channel'];
        $customer = $this->customer($request);
        $token = $customer === null ? (ResolveGuestToken::from($request) ?? (string) Str::uuid()) : null;
        $data = $request->only(['subject', 'body', 'guest_name', 'guest_email']);

        $conversation = $channel === SupportConversation::TICKET
            ? $this->conversations->startTicket($customer, $data, $token)
            : $this->conversations->startChat($customer, $data, $token);

        return APIResponse::created([
            ...$this->presenter->conversation($conversation, false, true),
            'guest_token' => $token,
        ], $channel === SupportConversation::TICKET ? 'Ticket opened' : 'Chat started');
    }

    public function show(Request $request, SupportConversation $conversation): JsonResponse
    {
        return APIResponse::success($this->presenter->conversation($this->own($request, $conversation), false, true));
    }
}
