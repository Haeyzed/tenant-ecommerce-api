<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Shared\Support\FrontendUrl;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Password;

/**
 * The first super-admin (spec §7.6). Runs only while no platform user
 * exists. No password is seeded: the user receives a password-set link.
 */
final class InitialPlatformAdminSeeder extends Seeder
{
    public function __construct(private readonly NotificationDispatchService $notifications) {}

    public function run(): void
    {
        if (PlatformUser::query()->exists()) {
            return;
        }

        $email = config('app.platform_admin_email');
        $name = config('app.platform_admin_name');

        if (blank($email) || blank($name)) {
            $this->command?->warn('PLATFORM_ADMIN_EMAIL and PLATFORM_ADMIN_NAME are not set: no platform admin created.');

            return;
        }

        /** @var PlatformUser $user */
        $user = PlatformUser::query()->create([
            'name' => (string) $name,
            'email' => strtolower((string) $email),
            'password' => null,
            'is_active' => true,
        ]);
        $user->assignRole('super-admin');

        $token = Password::broker('platform_users')->createToken($user);

        $this->notifications->dispatch('platform_user.invited', $user, [
            'name' => $user->name,
            'invited_by' => 'The platform installer',
            'set_password_url' => FrontendUrl::platformAdmin('/reset-password', ['token' => $token, 'email' => $user->email]),
            'expires_in_minutes' => (int) config('auth.passwords.platform_users.expire', 60),
        ]);

        $this->command?->info("Platform admin {$user->email} created; a password-set link was sent.");
    }
}
