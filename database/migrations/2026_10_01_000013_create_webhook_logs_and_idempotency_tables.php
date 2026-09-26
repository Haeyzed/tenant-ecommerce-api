<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord copies of webhook_logs (§15.6) and idempotency_keys (§70.11).
 * The tenant copies have the identical shape (database/migrations/tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('mode', 8);
            $table->string('provider_event_id');
            $table->string('event_type')->nullable();
            $table->string('provider_reference')->nullable();
            $table->longText('payload');
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['mode', 'provider', 'provider_event_id']);
            $table->index(['provider', 'provider_reference']);
            $table->index('processed_at');
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 128);
            $table->string('route', 191);
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->dateTime('locked_until');
            $table->dateTime('created_at');
            $table->dateTime('expires_at');

            $table->unique(['scope', 'route', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('webhook_logs');
    }
};
