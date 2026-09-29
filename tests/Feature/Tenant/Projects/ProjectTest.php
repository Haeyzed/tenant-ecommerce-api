<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    // A staff member who may work on projects, nothing more.
    $this->dev = User::query()->create(['name' => 'Dayo Dev', 'email' => 'dayo@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->dev->givePermissionTo(['projects.view', 'projects.tasks.view', 'projects.tasks.status']);
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    $this->devAuth = ['Authorization' => 'Bearer '.$this->dev->createToken('t', ['staff'])->plainTextToken];
    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);

    $this->tenantJson('POST', '/api/admin/modules/project_management/enable', [], $this->staff)->assertOk();
    $this->category = $this->tenantJson('POST', '/api/admin/project-categories', ['name' => 'Fit-outs'], $this->staff)->assertCreated()->json('data');
});

it('tracks projects and tasks, notifying only where the toggles ask, and narrows what a scoped user sees', function (): void {
    $project = $this->tenantJson('POST', '/api/admin/projects', ['title' => 'Lekki shop fit-out', 'project_category_id' => $this->category['id'],
        'customer_id' => $this->ada->id, 'priority' => 'high', 'notify_assigned_employees_whatsapp' => true, 'notify_customer_whatsapp' => true,
        'user_ids' => [$this->dev->id]], $this->staff)->assertCreated()
        ->assertJsonPath('data.status', 'not_started')->assertJsonPath('data.assigned_users.0.id', $this->dev->id)->assertJsonPath('data.customer.id', $this->ada->id)->json('data');
    Notification::assertSentTo($this->dev, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'project.assigned' && str_contains($n->body, 'Lekki shop fit-out'));

    // Only the newly assigned are told.
    $this->tenantJson('PATCH', "/api/admin/projects/{$project['id']}/assign-employees", ['user_ids' => [$this->dev->id, $this->owner->id]], $this->staff)->assertOk()
        ->assertJsonCount(2, 'data.assigned_users');
    Notification::assertSentToTimes($this->dev, TemplatedNotification::class, 1);
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'project.assigned');
    $this->tenantJson('PATCH', "/api/admin/projects/{$project['id']}/assign-employees", ['user_ids' => [999999]], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'user_invalid');

    // The customer hears about status changes.
    $this->tenantJson('PATCH', "/api/admin/projects/{$project['id']}/status", ['status' => 'in_progress'], $this->staff)->assertOk()->assertJsonPath('data.status', 'in_progress');
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'project.customer_update' && str_contains($n->body, 'in progress'));

    // Tasks: one for Dayo (late, notified), one for the owner (no notification).
    $late = $this->tenantJson('POST', "/api/admin/projects/{$project['id']}/tasks", ['title' => 'Order shelving', 'assigned_user_id' => $this->dev->id,
        'end_date' => today()->subDay()->toDateString(), 'estimated_hours' => 6, 'send_whatsapp_notification' => true], $this->staff)->assertCreated()
        ->assertJsonPath('data.is_overdue', true)->assertJsonPath('data.estimated_hours', '6.00')->json('data');
    Notification::assertSentTo($this->dev, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'project_task.assigned' && str_contains($n->body, 'Order shelving'));
    $mine = $this->tenantJson('POST', "/api/admin/projects/{$project['id']}/tasks", ['title' => 'Sign the lease', 'assigned_user_id' => $this->owner->id], $this->staff)->assertCreated()->json('data');
    Notification::assertNotSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'project_task.assigned');
    $this->tenantJson('GET', "/api/admin/projects/{$project['id']}/tasks?overdue=1", [], $this->staff)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late['id']);

    // A second project Dayo is not on.
    $other = $this->tenantJson('POST', '/api/admin/projects', ['title' => 'Warehouse racking', 'project_category_id' => $this->category['id']], $this->staff)->assertCreated()->json('data');

    // Narrowed by staff_data_access_scope: Dayo sees his project, and only his tasks.
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('staff_data_access_scope', 'own');
    $this->tenantJson('GET', '/api/admin/projects', [], $this->devAuth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $project['id']);
    $this->tenantJson('GET', "/api/admin/projects/{$other['id']}", [], $this->devAuth)->assertNotFound();
    $this->tenantJson('GET', "/api/admin/projects/{$project['id']}/tasks", [], $this->devAuth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late['id']);
    $this->tenantJson('PATCH', "/api/admin/projects/{$project['id']}/tasks/{$mine['id']}/status", ['status' => 'completed'], $this->devAuth)->assertNotFound();
    $this->tenantJson('PATCH', "/api/admin/projects/{$project['id']}/tasks/{$late['id']}/status", ['status' => 'completed'], $this->devAuth)->assertOk()
        ->assertJsonPath('data.is_overdue', false)->assertJsonPath('data.completed_at', fn (?string $at): bool => $at !== null);
    $this->tenantJson('POST', '/api/admin/projects', ['title' => 'X', 'project_category_id' => $this->category['id']], $this->devAuth)->assertForbidden();
    $this->tenantJson('GET', '/api/admin/projects', [], $this->staff)->assertOk()->assertJsonCount(2, 'data');

    // Figures, then completion.
    $strip = collect($this->tenantJson('GET', '/api/admin/projects/metrics?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($strip)->toMatchArray(['active' => 1, 'on_hold' => 0, 'overdue_tasks' => 0, 'completed' => 0]);
    $this->tenantJson('PATCH', "/api/admin/projects/{$project['id']}", ['status' => 'completed', 'description' => 'Handed over'], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.description', 'Handed over');
    Notification::assertSentToTimes($this->ada, TemplatedNotification::class, 2);
    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/projects?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($section)->toMatchArray(['active' => 0, 'completed' => 1]);

    // A category in use stays; a project goes with its tasks.
    $this->tenantJson('DELETE', "/api/admin/project-categories/{$this->category['id']}", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'project_category_in_use');
    $this->tenantJson('DELETE', "/api/admin/projects/{$other['id']}", [], $this->staff)->assertOk();
    $this->tenantJson('GET', '/api/admin/project-categories', [], $this->staff)->assertOk()->assertJsonPath('data.0.projects_count', 1);
});
