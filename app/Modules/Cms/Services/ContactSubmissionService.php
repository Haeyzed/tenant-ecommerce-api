<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\ContactSubmission;
use App\Modules\Cms\Support\CmsScope;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * The public contact form in both scopes (spec §24.7).
 */
final readonly class ContactSubmissionService
{
    public function __construct(
        private NotificationDispatchService $notifications,
        private PlatformSettingsService $platformSettings,
    ) {}

    /**
     * Stores the message and alerts the owner of the scope. A filled
     * honeypot field ("website") is accepted and silently dropped.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, Request $request, ?Model $customer = null): ?ContactSubmission
    {
        $validated = validator($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        if (filled($validated['website'] ?? null)) {
            return null;
        }

        unset($validated['website']);

        /** @var ContactSubmission $submission */
        $submission = ContactSubmission::query()->create([
            ...$validated,
            'email' => strtolower($validated['email']),
            'customer_id' => CmsScope::isTenant() ? $customer?->getKey() : null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ]);

        $variables = ['name' => $submission->name, 'email' => $submission->email, 'message' => $submission->message];

        if (CmsScope::isTenant()) {
            // Audience "admin": the store's owner and admins (§17.9).
            $this->notifications->dispatch('contact.submission_received', $submission, $variables);
        } elseif (filled($support = $this->platformSettings->get('support_email'))) {
            $this->notifications->dispatch('platform.contact_submission_received', Notification::route('mail', (string) $support), $variables);
        }

        return $submission;
    }

    /**
     * @param  array{status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, ContactSubmission>
     */
    public function list(array $filters): LengthAwarePaginator
    {
        return ContactSubmission::query()
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function markRead(ContactSubmission $submission, Model $by): ContactSubmission
    {
        if ($submission->status === 'new') {
            $submission->forceFill(['status' => 'read', 'handled_by_id' => $by->getKey()])->save();
        }

        return $submission;
    }

    public function archive(ContactSubmission $submission, Model $by): ContactSubmission
    {
        $submission->forceFill(['status' => 'archived', 'handled_by_id' => $by->getKey()])->save();

        return $submission;
    }

    public function delete(ContactSubmission $submission): void
    {
        $submission->delete();
    }

    /**
     * Deletes submissions older than the retention period (24 months).
     */
    public function purgeExpired(): int
    {
        $deleted = 0;

        do {
            $ids = ContactSubmission::query()
                ->where('created_at', '<', now()->subMonths((int) config('cms.contact_retention_months', 24)))
                ->limit(1000)
                ->pluck('id');

            $deleted += $ids->isEmpty() ? 0 : ContactSubmission::query()->whereKey($ids)->delete();
        } while ($ids->count() === 1000);

        return $deleted;
    }
}
