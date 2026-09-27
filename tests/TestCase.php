<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Tests\Support\InteractsWithBilling;
use Tests\Support\InteractsWithPlans;
use Tests\Support\InteractsWithTenants;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithBilling;
    use InteractsWithPlans;
    use InteractsWithTenants;
    use RefreshDatabase;

    /**
     * The landlord connection is wrapped in a transaction per test. Tenant
     * connections are wrapped when tenancy is bootstrapped (see setUp).
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = ['landlord'];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Every tenant database touched by a test runs inside a transaction
         * that is rolled back when tenancy ends (the tenant connection is
         * purged, which rolls back the open transaction).
         */
        Event::listen(TenancyBootstrapped::class, function (): void {
            $connection = DB::connection('tenant');

            if (self::$wrapTenantTransactions && $connection->transactionLevel() === 0) {
                $connection->beginTransaction();
            }
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Media disks point here under phpunit.xml; nothing survives a test.
        File::deleteDirectory(base_path('storage/framework/testing/media'));

        parent::tearDown();
    }
}
