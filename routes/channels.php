<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Customers\Models\Customer;
use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Services\SupportConversationService;
use App\Modules\Support\Support\GuestSupportActor;
use App\Modules\Users\Models\User;
use App\Shared\Support\TenantChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (spec §72.4)
|--------------------------------------------------------------------------
|
| Loaded by AppServiceProvider. Tenant channels use TenantChannel::pattern()
| and must check that {tenantId} is the current tenant.
|
*/

// Platform helpdesk (§21.2): staff users of the conversation's tenant, and
// platform users who may read the helpdesk.
Broadcast::channel('platform-support-conversation.{conversationId}', static function (object $user, int $conversationId): bool {
    $conversation = PlatformSupportConversation::query()->find($conversationId);

    if ($conversation === null) {
        return false;
    }

    if ($user instanceof PlatformUser) {
        return $user->is_active && $user->hasPermissionTo('platform-support.conversations.view', 'platform');
    }

    return $user instanceof User
        && $user->is_active
        && tenant()?->getTenantKey() === $conversation->tenant_id;
});

// Customer support (§59.2). Every tenant channel checks that {tenantId} is
// the current tenant. A staff agent is an active user who sees the inbox.
$supportAgent = static fn (object $user): bool => $user instanceof User && $user->is_active
    && $user->hasPermissionTo(SupportConversationService::VIEW, 'staff');

// The conversation's customer, the guest whose token matches, and agents.
Broadcast::channel(TenantChannel::pattern('support-conversation.{conversationId}'), static function (object $user, string $tenantId, int $conversationId) use ($supportAgent): bool {
    if (tenant()?->getTenantKey() !== $tenantId) {
        return false;
    }

    $conversation = SupportConversation::query()->find($conversationId);

    return match (true) {
        $conversation === null => false,
        $user instanceof Customer => $conversation->customer_id === $user->id,
        $user instanceof GuestSupportActor => $conversation->customer_id === null && $conversation->guest_token !== null
            && hash_equals($conversation->guest_token, $user->guestToken),
        default => $supportAgent($user),
    };
});

// The staff inbox: new messages, internal notes and status changes.
Broadcast::channel(TenantChannel::pattern('support-inbox'), static fn (object $user, string $tenantId): bool => tenant()?->getTenantKey() === $tenantId && $supportAgent($user));

// Which agents are online (presence).
Broadcast::channel(TenantChannel::pattern('support-agents-online'), static function (object $user, string $tenantId) use ($supportAgent): array|false {
    return tenant()?->getTenantKey() === $tenantId && $supportAgent($user) ? ['id' => $user->id, 'name' => $user->name] : false;
});
