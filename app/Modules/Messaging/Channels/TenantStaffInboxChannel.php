<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Channels;

use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * In-app delivery of a landlord notification to a tenant (spec §17.1,
 * UD-10): one row in the tenant database's own notifications table for
 * each active owner and admin, so the staff inbox (§17.7) shows it with a
 * per-user read state. Nothing is stored in the landlord database.
 */
final class TenantStaffInboxChannel
{
    /** Statuses whose staff can still sign in and read the inbox. */
    public const array STATUSES = [TenantStatus::Active, TenantStatus::Suspended];

    public static function canDeliver(Tenant $tenant): bool
    {
        return $tenant->provisioned_at !== null && in_array($tenant->status, self::STATUSES, true);
    }

    public function send(object $notifiable, TemplatedNotification $notification): void
    {
        if (! $notifiable instanceof Tenant || ! self::canDeliver($notifiable)) {
            return;
        }

        $payload = $notification->toArray($notifiable) + ['source' => 'platform'];

        // All rows or none, so a retried job never duplicates a message.
        $notifiable->run(static fn () => DB::connection('tenant')->transaction(static function () use ($notification, $payload): void {
            $staff = User::query()->role(['owner', 'admin'], 'staff')->where('is_active', true)->get();

            foreach ($staff as $user) {
                $user->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => $notification->key,
                    'data' => $payload,
                ]);
            }
        }));
    }
}
