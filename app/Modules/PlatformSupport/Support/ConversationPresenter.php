<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Support;

use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\PlatformSupport\Models\PlatformSupportMessage;
use App\Modules\PlatformSupport\Models\PlatformSupportMessageAttachment;

/**
 * One shape for both sides; tenants never see internal notes or staff
 * assignment details.
 */
final class ConversationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(PlatformSupportConversation $c, bool $platform): array
    {
        return array_filter([
            'id' => $c->id,
            'tenant' => $platform && $c->relationLoaded('tenant') ? ['id' => $c->tenant?->id, 'name' => $c->tenant?->name] : null,
            'subject' => $c->subject,
            'category' => $c->category,
            'status' => $c->status,
            'priority' => $c->priority,
            'raised_by' => ['name' => $c->raised_by_name, 'email' => $c->raised_by_email],
            'assignee' => $platform && $c->relationLoaded('assignee') && $c->assignee !== null ? ['id' => $c->assignee->id, 'name' => $c->assignee->name] : null,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ], static fn (mixed $v, string $k): bool => $v !== null || in_array($k, ['last_message_at'], true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(PlatformSupportConversation $c, bool $platform): array
    {
        $messages = $c->messages()
            ->with('attachments.media')
            ->when(! $platform, static fn ($q) => $q->where('is_internal_note', false))
            ->orderBy('id')
            ->get()
            ->map(static fn (PlatformSupportMessage $m): array => [
                'id' => $m->id,
                'sender_type' => $m->sender_type,
                'sender_label' => $m->sender_label,
                'body' => $m->body,
                'is_internal_note' => $m->is_internal_note,
                'read_at' => $m->read_at?->toIso8601String(),
                'attachments' => $m->attachments->map(static fn (PlatformSupportMessageAttachment $a): array => [
                    'id' => $a->id,
                    'name' => $a->getFirstMedia('attachment')?->name,
                    'size' => $a->getFirstMedia('attachment')?->size,
                    'mime_type' => $a->getFirstMedia('attachment')?->mime_type,
                ])->values()->all(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return self::summary($c, $platform) + ['messages' => $messages];
    }
}
