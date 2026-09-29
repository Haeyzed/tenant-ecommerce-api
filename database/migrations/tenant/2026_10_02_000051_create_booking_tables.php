<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Booking (§66.1). products.is_bookable and duration_minutes exist since
| the catalogue tables (§27). Times are stored in UTC and read in the
| tenant timezone; weekly hours are local wall-clock times.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_booking_staff', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('booking_staff_id')->constrained('booking_staff')->cascadeOnDelete();

            $table->unique(['product_id', 'booking_staff_id']);
        });

        Schema::create('booking_availability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_staff_id')->constrained('booking_staff')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['booking_staff_id', 'day_of_week']);
        });

        Schema::create('booking_time_off', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_staff_id')->constrained('booking_staff')->cascadeOnDelete();
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['booking_staff_id', 'start_datetime']);
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('booking_staff_id')->constrained('booking_staff')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_email', 255)->nullable();
            $table->string('guest_phone', 32)->nullable();
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->string('status', 16)->default('confirmed');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['booking_staff_id', 'start_datetime']);
            $table->index(['customer_id', 'start_datetime']);
            $table->index(['status', 'start_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_time_off');
        Schema::dropIfExists('booking_availability');
        Schema::dropIfExists('product_booking_staff');
        Schema::dropIfExists('booking_staff');
    }
};
