<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant group 3 (spec §79.2): the tenant's own gateway credentials per
 * provider and mode (§15.4). Secrets are encrypted by the model casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_payment_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('mode', 8);
            $table->string('public_key')->nullable();
            $table->text('secret_key');
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('enabled_for_online')->default(true);
            $table->dateTime('credentials_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_settings');
    }
};
