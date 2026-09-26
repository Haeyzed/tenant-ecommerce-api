<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: tax rates (§35.1), shipping zones and methods (§36.1) and drivers
 * (§36.2). Shipments and delivery assignments reference orders and are
 * created with them. World ids live in the landlord database (no foreign
 * key); a nullable state is made unique through state_key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64);
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('state_key')->storedAs('COALESCE(`state_id`, 0)');
            $table->string('tax_class', 16)->default('standard');
            $table->decimal('rate_percentage', 7, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'state_key', 'tax_class'], 'tax_rates_region_class_unique');
        });

        Schema::create('shipping_zones', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_zone_regions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('state_key')->storedAs('COALESCE(`state_id`, 0)');

            $table->unique(['shipping_zone_id', 'country_id', 'state_key'], 'shipping_zone_regions_unique');
            $table->index(['country_id', 'state_key']);
        });

        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('fulfillment_type', 16);
            $table->string('courier_provider', 64)->nullable();
            $table->decimal('cost', 18, 4)->default(0);
            $table->unsignedInteger('estimated_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['shipping_zone_id', 'is_active']);
        });

        Schema::create('drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 32)->unique();
            $table->dateTime('phone_verified_at')->nullable();
            $table->string('pin_hash')->nullable();
            $table->string('vehicle_type', 32)->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('is_available')->default(true);
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_available']);
        });
    }

    public function down(): void
    {
        foreach (['drivers', 'shipping_methods', 'shipping_zone_regions', 'shipping_zones', 'tax_rates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
