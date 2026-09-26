<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant group 3 (spec §79.2): tenant_settings (§13.4) and
 * storefront_settings (§13.5). Payment and messaging settings tables are
 * created by their own modules' migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tenant_settings', 'storefront_settings'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('key', 128)->unique();
                $table->text('value')->nullable();
                $table->string('type', 16);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_settings');
        Schema::dropIfExists('tenant_settings');
    }
};
