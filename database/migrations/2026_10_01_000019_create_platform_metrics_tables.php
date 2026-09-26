<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: platform_daily_metrics and tenant_usage_snapshots (§22.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('metric', 64);
            $table->string('dimension', 64)->default('');
            $table->decimal('value', 20, 4);
            $table->dateTime('created_at');

            $table->unique(['date', 'metric', 'dimension']);
        });

        Schema::create('tenant_usage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->date('date');
            $table->json('usage');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('gross_sales', 18, 4)->default(0);
            $table->char('base_currency', 3);
            $table->unsignedInteger('webhook_failures')->default(0);
            $table->unsignedInteger('failed_postings')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage_snapshots');
        Schema::dropIfExists('platform_daily_metrics');
    }
};
