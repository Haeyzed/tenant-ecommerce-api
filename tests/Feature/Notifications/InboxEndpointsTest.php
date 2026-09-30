<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Customers\Models\Customer;
use App\Modules\Users\Models\User;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
| BG-08 (platform-user inbox) and BG-09 (mark all read).
*/

function inboxNotify(Model $notifiable, string $subject): string
{
    $id = (string) Str::uuid();
    $notifiable->notifications()->create(['id' => $id, 'type' => 'test.message', 'data' => ['subject' => $subject, 'body' => 'Body']]);

    return $id;
}

it('gives platform users their own inbox with mark read and mark all read', function (): void {
    $this->seed(PlatformAccessSeeder::class);
    $root = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $root->assignRole('super-admin');
    // No role at all: the inbox is self-service, not permission-gated.
    $clerk = PlatformUser::query()->create(['name' => 'Clerk', 'email' => 'clerk@platform.test', 'password' => 'Secret123', 'is_active' => true]);

    $first = inboxNotify($clerk, 'First');
    inboxNotify($clerk, 'Second');
    $rootOwn = inboxNotify($root, 'Root only');
    $auth = ['Authorization' => 'Bearer '.$clerk->createToken('t', ['platform'])->plainTextToken];

    $this->landlordJson('GET', '/api/admin/notifications', [], $auth)->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 2)->assertJsonPath('meta.pagination.total', 2);
    $this->landlordJson('POST', "/api/admin/notifications/{$rootOwn}/read", [], $auth)->assertNotFound();
    $this->landlordJson('POST', "/api/admin/notifications/{$first}/read", [], $auth)->assertOk()->assertJsonPath('data.id', $first);
    $this->landlordJson('GET', '/api/admin/notifications?unread=1', [], $auth)->assertJsonCount(1, 'data');

    $this->landlordJson('POST', '/api/admin/notifications/read-all', [], $auth)->assertOk()->assertJsonPath('data.marked', 1);
    $this->landlordJson('GET', '/api/admin/notifications', [], $auth)->assertJsonPath('meta.unread_count', 0);
    expect($root->unreadNotifications()->count())->toBe(1);

    $this->landlordJson('GET', '/api/admin/notifications')->assertUnauthorized();
});

it('marks all read for staff and customers, each within their own inbox', function (): void {
    $tenant = $this->createTenant('a');
    $this->subscribe($tenant, 'basic');
    tenancy()->initialize($tenant);

    $staff = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $staff->assignRole('staff');
    $customer = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'Secret123']);
    inboxNotify($staff, 'Staff one');
    inboxNotify($staff, 'Staff two');
    inboxNotify($customer, 'Customer one');

    $this->tenantJson('POST', '/api/admin/notifications/read-all', [], ['Authorization' => 'Bearer '.$staff->createToken('t', ['staff'])->plainTextToken])
        ->assertOk()->assertJsonPath('data.marked', 2);
    tenancy()->initialize($tenant);
    expect($customer->unreadNotifications()->count())->toBe(1);

    $this->tenantJson('POST', '/api/notifications/read-all', [], ['Authorization' => 'Bearer '.$customer->createToken('t', ['customer'])->plainTextToken])
        ->assertOk()->assertJsonPath('data.marked', 1);
});
