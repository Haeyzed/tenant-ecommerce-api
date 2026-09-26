<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: platform_settings (§13.2) and tenant_platform_settings (§13.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 64);
            $table->string('key', 128)->unique();
            $table->text('value')->nullable();
            $table->string('type', 16);
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('tenant_platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('key', 128);
            $table->text('value')->nullable();
            $table->string('type', 16);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_platform_settings');
        Schema::dropIfExists('platform_settings');
    }
};
