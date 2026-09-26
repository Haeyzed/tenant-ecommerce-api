<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\Users\Models\User;
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
