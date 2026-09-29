<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Platform (landlord) exports (D-134): the landlord twin of data_exports,
| for tenants, subscriptions, payments and affiliates.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('export_type', 64);
            $table->json('parameters');
            $table->string('format', 8);
            $table->string('status', 16)->default('queued');
            $table->foreignId('requested_by_id')->constrained('platform_users')->restrictOnDelete();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_exports');
    }
};
