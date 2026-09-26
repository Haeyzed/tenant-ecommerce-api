<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: module_notices (§18.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 64);
            $table->string('tenant_id')->nullable();
            $table->string('type', 16);
            $table->string('behavior', 16)->nullable();
            $table->string('title');
            $table->text('message');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['module_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_notices');
    }
};
