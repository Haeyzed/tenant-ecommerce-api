<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: database_servers (§6.6), tenants and domains (§7.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('host');
            $table->unsignedInteger('port')->default(3306);
            $table->string('read_host')->nullable();
            $table->string('username');
            $table->text('password');
            $table->unsignedInteger('max_tenants');
            $table->unsignedInteger('tenant_count')->default(0);
            $table->boolean('is_accepting_tenants')->default(true);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('owner_name');
            $table->string('email')->index();
            $table->string('status', 32);
            $table->dateTime('trial_consumed_at')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->foreignId('database_server_id')->nullable()->constrained('database_servers');
            $table->string('schema_version')->nullable();
            $table->unsignedBigInteger('country_id');
            $table->char('default_currency', 3);
            $table->string('permissions_version')->nullable();
            $table->dateTime('provisioned_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->string('status_reason')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('purge_after')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['status', 'timezone']);
            $table->index('purge_after');
        });

        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->string('tenant_id');
            $table->string('type', 16)->default('subdomain');
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32)->default('active');
            $table->string('verification_token')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->string('routing_type', 16)->nullable();
            $table->string('tls_status', 16)->nullable();
            $table->dateTime('tls_checked_at')->nullable();
            $table->dateTime('tls_expires_at')->nullable();
            $table->dateTime('last_dns_check_at')->nullable();
            $table->json('dns_check_result')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('database_servers');
    }
};
