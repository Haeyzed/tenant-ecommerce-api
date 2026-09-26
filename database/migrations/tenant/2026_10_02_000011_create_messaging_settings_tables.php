<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant group 3 (spec §79.2): SMS gateway and WhatsApp settings (§16.3,
 * §16.4). Credentials are encrypted by the model casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_gateway_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->unique();
            $table->text('credentials');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('whatsapp_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->text('access_token')->nullable();
            $table->boolean('is_active')->default(false);
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings');
        Schema::dropIfExists('sms_gateway_settings');
    }
};
