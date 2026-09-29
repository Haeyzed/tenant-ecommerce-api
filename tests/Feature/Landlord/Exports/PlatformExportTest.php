<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Exports\Jobs\GeneratePlatformExport;
use App\Modules\Exports\Models\PlatformExport;
use App\Modules\Exports\Services\PlatformExportService;
use App\Shared\Exceptions\ApiException;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(PlatformAccessSeeder::class);
    $this->admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->admin->assignRole('super-admin');
    $this->token = ['Authorization' => 'Bearer '.$this->admin->createToken('t', ['platform'])->plainTextToken];
    $this->createTenant('a');
});

it('exports platform lists to its requester only, without secrets', function (): void {
    Affiliate::query()->forceCreate(['public_id' => 'aff_1', 'name' => '=Evil', 'email' => 'aff@example.test', 'password' => 'Secret123', 'status' => 'approved',
        'payout_method' => 'bank_transfer', 'payout_details' => ['account_number' => '0123456789']]);

    $id = $this->landlordJson('POST', '/api/admin/exports', ['export_type' => 'tenants', 'format' => 'xlsx'], $this->token)->assertStatus(202)->json('data.id');
    (new GeneratePlatformExport($id))->handle(app(PlatformExportService::class));
    $export = PlatformExport::query()->findOrFail($id);
    $sheet = IOFactory::load($export->getFirstMediaPath('file'))->getActiveSheet();
    expect($export->status)->toBe('completed')->and($export->row_count)->toBe(1)->and($sheet->getCell('B2')->getValue())->toBe('Tenant A');

    $affiliates = app(PlatformExportService::class)->request('affiliates', [], 'csv', $this->admin);
    app(PlatformExportService::class)->generate($affiliates);
    $csv = (string) file_get_contents($affiliates->refresh()->getFirstMediaPath('file'));
    expect($csv)->toContain("'=Evil")->not->toContain('0123456789')->not->toContain('Secret123');

    $this->landlordJson('GET', "/api/admin/exports/{$id}", [], $this->token)->assertOk()->assertJsonPath('data.row_count', 1);
    $this->landlordJson('GET', "/api/admin/exports/{$id}/download", [], $this->token)->assertOk();

    // Another platform user without the permission cannot export, nor see this one.
    $clerk = PlatformUser::query()->create(['name' => 'Clerk', 'email' => 'clerk@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $clerkToken = ['Authorization' => 'Bearer '.$clerk->createToken('t', ['platform'])->plainTextToken];
    $this->landlordJson('GET', "/api/admin/exports/{$id}/download", [], $clerkToken)->assertStatus(403);
    expect(fn () => app(PlatformExportService::class)->request('tenants', [], 'csv', $clerk))->toThrow(ApiException::class);
    $this->landlordJson('POST', '/api/admin/exports', ['export_type' => 'nope', 'format' => 'csv'], $this->token)->assertStatus(422);
});
