<?php

declare(strict_types=1);

use App\Modules\Exports\Jobs\GenerateExport;
use App\Modules\Exports\Services\DataExportService;
use App\Modules\Exports\Support\ExportDefinition;
use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    app(ExportRegistry::class)->register(new ExportDefinition(
        type: 'test:staff',
        label: 'Staff list',
        rules: ['active' => ['sometimes', 'boolean']],
        columns: ['name' => 'Name', 'email' => 'Email'],
        rows: static fn (array $p): iterable => User::query()->orderBy('id')->lazy(100)->map(static fn (User $u): array => ['name' => $u->name, 'email' => $u->email]),
        permission: 'settings.view',
    ));

    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
    tenancy()->initialize($this->tenant);

    $this->owner = User::query()->create(['name' => '=HYPERLINK("x")', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
});

it('queues an export once for identical requests', function (): void {
    Bus::fake([GenerateExport::class]);

    $id = $this->tenantJson('POST', '/api/admin/exports', ['export_type' => 'test:staff', 'format' => 'csv', 'parameters' => ['active' => true]], $this->auth)
        ->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data.id');

    $this->tenantJson('POST', '/api/admin/exports', ['export_type' => 'test:staff', 'format' => 'csv', 'parameters' => ['active' => true]], $this->auth)
        ->assertStatus(202)->assertJsonPath('data.id', $id);

    Bus::assertDispatchedTimes(GenerateExport::class, 1);
});

it('rejects unknown types, unsupported formats and missing permissions', function (): void {
    $this->tenantJson('POST', '/api/admin/exports', ['export_type' => 'nope', 'format' => 'csv'], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'export_type_unknown');
    $this->tenantJson('POST', '/api/admin/exports', ['export_type' => 'test:staff', 'format' => 'pdf'], $this->auth)->assertStatus(422);

    $clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    expect(fn () => app(DataExportService::class)->request('test:staff', [], 'csv', $clerk))
        ->toThrow(ApiException::class);
});

it('generates a CSV with formula cells neutralised, then notifies and serves it', function (): void {
    $export = app(DataExportService::class)->request('test:staff', [], 'csv', $this->owner);
    (new GenerateExport($export->id))->handle(app(ExportRegistry::class), app(NotificationDispatchService::class));

    $export->refresh();
    expect($export->status)->toBe('completed')->and($export->row_count)->toBe(1)->and($export->expires_at->isFuture())->toBeTrue();

    $content = file_get_contents($export->getFirstMediaPath('file'));
    expect($content)->toContain('Name,Email')->toContain("\"'=HYPERLINK(\"\"x\"\")\"");

    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn ($n): bool => $n->key === 'export.ready');

    $this->tenantJson('GET', "/api/admin/exports/{$export->id}/download", [], $this->auth)->assertOk()->assertDownload("test:staff-{$export->id}.csv");
});

it('expires files after seven days', function (): void {
    $export = app(DataExportService::class)->request('test:staff', [], 'json', $this->owner);
    (new GenerateExport($export->id))->handle(app(ExportRegistry::class), app(NotificationDispatchService::class));

    $this->travel(8)->days();
    app(DataExportService::class)->expireFiles();

    expect($export->refresh()->status)->toBe('expired')->and($export->getFirstMedia('file'))->toBeNull();
    $this->tenantJson('GET', "/api/admin/exports/{$export->id}/download", [], $this->auth)->assertStatus(410);
});

it('never shows one user another user\'s exports', function (): void {
    Bus::fake([GenerateExport::class]);
    $export = app(DataExportService::class)->request('test:staff', [], 'csv', $this->owner);
    $other = User::query()->create(['name' => 'Other', 'email' => 'other@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $other->assignRole('admin');

    $this->tenantJson('GET', "/api/admin/exports/{$export->id}", [], ['Authorization' => 'Bearer '.$other->createToken('t', ['staff'])->plainTextToken])
        ->assertNotFound();
});
