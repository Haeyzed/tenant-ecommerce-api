<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\PlatformSupport\Events\PlatformSupportConversationStatusChanged;
use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\PlatformSupport\Models\PlatformSupportMessage;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The platform helpdesk inbox (spec §21.3). Landlord service; tenant staff
 * reach it from the tenant domain under §6.4.
 */
final readonly class PlatformSupportConversationService
{
    public function __construct(private PlatformSupportMessageService $messages) {}

    /**
     * @param  array{id: int, name: string, email: string}  $raisedBy
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $attachments
     */
    public function openConversation(Tenant $tenant, array $raisedBy, array $data, array $attachments = []): PlatformSupportConversation
    {
        $validated = validator($data, [
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(PlatformSupportConversation::CATEGORIES)],
            'body' => ['required', 'string', 'max:10000'],
        ])->validate();

        $conversation = DB::connection('landlord')->transaction(static function () use ($tenant, $raisedBy, $validated): PlatformSupportConversation {
            /** @var PlatformSupportConversation $conversation */
            $conversation = PlatformSupportConversation::query()->create([
                'tenant_id' => $tenant->getTenantKey(),
                'raised_by_user_id' => $raisedBy['id'],
                'raised_by_name' => $raisedBy['name'],
                'raised_by_email' => $raisedBy['email'],
                'subject' => $validated['subject'],
                'category' => $validated['category'],
                'status' => 'open',
                'priority' => 'normal',
            ]);

            return $conversation;
        });

        $this->messages->sendMessage($conversation, [
            'sender_type' => PlatformSupportMessage::TENANT,
            'sender_id' => $raisedBy['id'],
            'sender_label' => $raisedBy['name'],
            'body' => $validated['body'],
        ], $attachments);

        return $conversation->refresh();
    }

    public function assignToStaff(PlatformSupportConversation $conversation, ?PlatformUser $staff, PlatformUser $by): PlatformSupportConversation
    {
        if ($staff !== null && ! $staff->is_active) {
            throw ApiException::unprocessable('assignee_inactive', 'Assign the conversation to an active platform user.');
        }

        $conversation->forceFill(['assigned_to' => $staff?->id])->save();
        ActivityRecorder::landlord('platform_support', 'Conversation assigned', $conversation, ['assigned_to' => $staff?->id], $by);

        return $conversation;
    }

    public function updateStatus(PlatformSupportConversation $conversation, string $status): PlatformSupportConversation
    {
        validator(['status' => $status], ['status' => ['required', Rule::in(PlatformSupportConversation::STATUSES)]])->validate();

        $conversation->forceFill(['status' => $status])->save();
        PlatformSupportConversationStatusChanged::dispatch($conversation->id, $conversation->status, $conversation->priority);

        return $conversation;
    }

    public function updatePriority(PlatformSupportConversation $conversation, string $priority): PlatformSupportConversation
    {
        validator(['priority' => $priority], ['priority' => ['required', Rule::in(PlatformSupportConversation::PRIORITIES)]])->validate();

        $conversation->forceFill(['priority' => $priority])->save();
        PlatformSupportConversationStatusChanged::dispatch($conversation->id, $conversation->status, $conversation->priority);

        return $conversation;
    }

    /**
     * The platform inbox: unassigned and urgent first.
     *
     * @param  array{tenant?: string|null, status?: string|null, category?: string|null, assignee?: int|string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<int, PlatformSupportConversation>
     */
    public function listConversations(array $filters): LengthAwarePaginator
    {
        return PlatformSupportConversation::query()
            ->with(['tenant:id,name,slug', 'assignee:id,name'])
            ->when($filters['tenant'] ?? null, static fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category'] ?? null, static fn ($q, $v) => $q->where('category', $v))
            ->when(($filters['assignee'] ?? null) === 'unassigned', static fn ($q) => $q->whereNull('assigned_to'))
            ->when(is_numeric($filters['assignee'] ?? null), static fn ($q) => $q->where('assigned_to', (int) $filters['assignee']))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderByDesc('last_message_at')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
    }

    /**
     * @return Collection<int, PlatformSupportConversation>
     */
    public function getForTenant(Tenant $tenant): Collection
    {
        return PlatformSupportConversation::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->orderByDesc('last_message_at')
            ->get();
    }
}
