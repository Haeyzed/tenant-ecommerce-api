<?php

declare(strict_types=1);

use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Tenancy\Jobs\DispatchTenantDailyMaintenance;
use App\Modules\Tenancy\Jobs\RunTenantDailyMaintenance;
use App\Modules\Tenancy\Models\TenantUsageSnapshot;
use App\Modules\Tenancy\Services\TenantUsageReporter;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a', ['timezone' => 'Africa/Lagos']);
    $this->subscribe($this->tenant, 'basic');
});

// Daily maintenance builds the sitemap on the tenant's real disk.
afterEach(function (): void {
    File::delete(base_path('storage/tenants/test-tenant-a/app/sitemap.xml'));
});

it('dispatches daily maintenance only to tenants whose local time is 01:00', function (): void {
    Bus::fake([RunTenantDailyMaintenance::class]);

    $this->travelTo(now()->setTimezone('Africa/Lagos')->setTime(1, 20)->utc());
    (new DispatchTenantDailyMaintenance)->handle();
    Bus::assertDispatched(RunTenantDailyMaintenance::class, fn ($job): bool => $job->tenantId === 'test-tenant-a');

    Bus::fake([RunTenantDailyMaintenance::class]);
    $this->travelTo(now()->setTimezone('Africa/Lagos')->setTime(14, 0)->utc());
    (new DispatchTenantDailyMaintenance)->handle();
    Bus::assertNotDispatched(RunTenantDailyMaintenance::class);
});

it('prunes expired tenant rows in isolation', function (): void {
    tenancy()->initialize($this->tenant);
    DB::connection('tenant')->table('activity_log')->insert([
        ['log_name' => 'x', 'description' => 'old', 'created_at' => now()->subDays(400), 'updated_at' => now()],
        ['log_name' => 'x', 'description' => 'new', 'created_at' => now()->subDays(10), 'updated_at' => now()],
    ]);

    app()->call([new RunTenantDailyMaintenance('test-tenant-a'), 'handle']);

    tenancy()->initialize($this->tenant);
    expect(DB::connection('tenant')->table('activity_log')->pluck('description')->all())->toBe(['new']);
});

it('reports the previous local day\'s usage snapshot to the landlord', function (): void {
    // 01:20 on the 25th in Lagos; the snapshot is for the 24th.
    $this->travelTo(CarbonImmutable::parse('2026-09-25 01:20:00', 'Africa/Lagos')->utc());

    tenancy()->initialize($this->tenant);
    User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    DB::connection('tenant')->table('webhook_logs')->insert([
        'provider' => 'paystack', 'mode' => 'live', 'provider_event_id' => 'evt-1', 'payload' => encrypt('[]'),
        'received_at' => CarbonImmutable::parse('2026-09-24 12:00:00', 'Africa/Lagos')->utc(), 'attempts' => 3, 'error' => 'boom',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(TenantUsageReporter::class)->contribute('orders_count', static fn (): int => 7);
    app(TenantUsageReporter::class)->report($this->tenant);
    // A re-run the same day replaces the row instead of duplicating it.
    $snapshot = app(TenantUsageReporter::class)->report($this->tenant);

    expect(TenantUsageSnapshot::query()->where('tenant_id', 'test-tenant-a')->count())->toBe(1)
        ->and($snapshot->date->toDateString())->toBe('2026-09-24')
        ->and($snapshot->usage['max_users'])->toBe(1)
        ->and($snapshot->orders_count)->toBe(7)
        ->and($snapshot->gross_sales)->toBe('0.0000')
        ->and($snapshot->base_currency)->toBe('USD')
        ->and($snapshot->webhook_failures)->toBe(1);
});

it('refuses an upload that would exceed the storage limit', function (): void {
    tenancy()->initialize($this->tenant);
    PlanLimit::query()->where('limit_key', 'max_storage_mb')->update(['limit_value' => 1]);
    app(PlanLimitService::class)->flush($this->tenant);

    app(StorageQuota::class)->assertAllows(512 * 1024);

    expect(fn () => app(StorageQuota::class)->assertAllows(UploadedFile::fake()->create('big.pdf', 2048)))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('limit_reached'));
});

it('rejects SVG and disguised uploads', function (): void {
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    // A real upload over a temp file: the fake file reports a MIME type
    // derived from its name, so it cannot exercise content sniffing.
    $path = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($path, '<?php echo 1;');
    $disguised = new UploadedFile($path, 'photo.png', null, null, true);
    $png = UploadedFile::fake()->image('photo.png', 10, 10);

    $rules = ['file' => UploadRules::image()];

    expect(Validator::make(['file' => $svg], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['file' => $disguised], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['file' => $png], $rules)->passes())->toBeTrue();
});
