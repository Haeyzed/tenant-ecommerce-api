<?php

declare(strict_types=1);

use App\Modules\Booking\Http\Controllers\Tenant\Admin\BookingController as AdminBookingController;
use App\Modules\Booking\Http\Controllers\Tenant\Admin\BookingStaffController;
use App\Modules\Booking\Http\Controllers\Tenant\Admin\TimeOffController;
use App\Modules\Booking\Http\Controllers\Tenant\BookingController;
use App\Modules\Booking\Http\Controllers\Tenant\SlotController;
use Illuminate\Support\Facades\Route;

/*
| Booking (spec §66.3). Feature `booking`. Cancelling and completing stay
| open while the module winds down, so booked appointments can close.
*/

Route::middleware(['tenant.public', 'feature:booking'])->name('tenant.public.')->group(function (): void {
    Route::get('products/{product}/available-slots', [SlotController::class, 'index'])->where('product', '[A-Za-z0-9-]+')->name('products.available-slots.index');
});

Route::middleware(['tenant.storefront', 'feature:booking'])->name('tenant.storefront.')->group(function (): void {
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
});

Route::middleware(['tenant.customer', 'feature:booking'])->prefix('account')->name('tenant.customer.account.')->group(function (): void {
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::patch('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->whereNumber('booking')->name('bookings.cancel');
});

Route::middleware(['tenant.admin', 'feature:booking', 'module.notice:booking'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('booking-staff', [BookingStaffController::class, 'index'])->name('booking-staff.index');
    Route::post('booking-staff', [BookingStaffController::class, 'store'])->name('booking-staff.store');
    Route::patch('booking-staff/{staff}', [BookingStaffController::class, 'update'])->whereNumber('staff')->name('booking-staff.update');
    Route::delete('booking-staff/{staff}', [BookingStaffController::class, 'destroy'])->whereNumber('staff')->name('booking-staff.destroy');
    Route::put('booking-staff/{staff}/availability', [BookingStaffController::class, 'availability'])->whereNumber('staff')->name('booking-staff.availability');
    Route::post('booking-staff/{staff}/time-off', [TimeOffController::class, 'store'])->whereNumber('staff')->name('booking-staff.time-off.store');
    Route::delete('booking-staff/{staff}/time-off/{timeOff}', [TimeOffController::class, 'destroy'])->whereNumber(['staff', 'timeOff'])->name('booking-staff.time-off.destroy');
    Route::post('products/{product}/booking-staff/{staff}', [BookingStaffController::class, 'attach'])->whereNumber(['product', 'staff'])->name('products.booking-staff.attach');
    Route::delete('products/{product}/booking-staff/{staff}', [BookingStaffController::class, 'detach'])->whereNumber(['product', 'staff'])->name('products.booking-staff.detach');

    Route::get('bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
    Route::post('bookings', [AdminBookingController::class, 'store'])->name('bookings.store');
    Route::patch('bookings/{booking}/reschedule', [AdminBookingController::class, 'reschedule'])->whereNumber('booking')->name('bookings.reschedule');
    Route::patch('bookings/{booking}/cancel', [AdminBookingController::class, 'cancel'])->whereNumber('booking')->name('bookings.cancel');
    Route::patch('bookings/{booking}/complete', [AdminBookingController::class, 'complete'])->whereNumber('booking')->name('bookings.complete');
    Route::patch('bookings/{booking}/no-show', [AdminBookingController::class, 'noShow'])->whereNumber('booking')->name('bookings.no-show');
    Route::post('bookings/{booking}/invoice', [AdminBookingController::class, 'invoice'])->whereNumber('booking')->middleware('usage.limit:max_orders_per_month')->name('bookings.invoice');
});
