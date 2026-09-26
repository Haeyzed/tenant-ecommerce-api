<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant group 4 (spec §79.2): FCM device tokens per actor (§16.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type', 32);
            $table->unsignedBigInteger('tokenable_id');
            $table->string('token', 512);
            $table->string('platform', 16);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('token');
            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_device_tokens');
    }
};
