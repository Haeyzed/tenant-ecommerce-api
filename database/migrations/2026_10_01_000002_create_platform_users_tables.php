<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: platform_users (§12.2) and their password broker table (§10.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->dateTime('email_verified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('preferences')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_user_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_user_password_reset_tokens');
        Schema::dropIfExists('platform_users');
    }
};
