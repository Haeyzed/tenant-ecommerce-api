<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Customer social login identities (D-132). In the tenant database, so an
| identity belongs to one store by construction. Provider tokens are never
| stored: they are used once to read the profile.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('provider_email', 255)->nullable();
            $table->boolean('provider_email_verified')->default(false);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            // One customer per provider identity, one identity per provider per customer.
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['customer_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_social_accounts');
    }
};
