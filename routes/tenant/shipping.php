<?php

declare(strict_types=1);

use App\Modules\Shipping\Http\Controllers\Tenant\Admin\DeliveryAssignmentController;
use App\Modules\Shipping\Http\Controllers\Tenant\Admin\DriverController;
use App\Modules\Shipping\Http\Controllers\Tenant\Admin\OrderShipmentController;
use App\Modules\Shipping\Http\Controllers\Tenant\Admin\ShipmentController;
use App\Modules\Shipping\Http\Controllers\Tenant\Admin\ShippingMethodController as AdminShippingMethodController;
use App\Modules\Shipping\Http\Controllers\Tenant\Admin\ShippingZoneController;
use App\Modules\Shipping\Http\Controllers\Tenant\Driver\DeliveryController;
use App\Modules\Shipping\Http\Controllers\Tenant\Driver\ProfileController;
use App\Modules\Shipping\Http\Controllers\Tenant\ShippingMethodController;
use App\Modules\Shipping\Http\Controllers\Tenant\TrackingController;
use Illuminate\Support\Facades\Route;

/*
| Shipping zones, methods, drivers, shipments, delivery assignments and
| tracking (spec §36.4). Core commerce.
*/

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.shipping.admin.')->group(function (): void {
    Route::get('shipping-zones', [ShippingZoneController::class, 'index'])->name('zones.index');
    Route::post('shipping-zones', [ShippingZoneController::class, 'store'])->name('zones.store');
    Route::patch('shipping-zones/{zone}', [ShippingZoneController::class, 'update'])->whereNumber('zone')->name('zones.update');
    Route::delete('shipping-zones/{zone}', [ShippingZoneController::class, 'destroy'])->whereNumber('zone')->name('zones.destroy');

    Route::get('shipping-methods', [AdminShippingMethodController::class, 'index'])->name('methods.index');
    Route::post('shipping-methods', [AdminShippingMethodController::class, 'store'])->name('methods.store');
    Route::patch('shipping-methods/{method}', [AdminShippingMethodController::class, 'update'])->whereNumber('method')->name('methods.update');
    Route::delete('shipping-methods/{method}', [AdminShippingMethodController::class, 'destroy'])->whereNumber('method')->name('methods.destroy');

    Route::get('shipments', [ShipmentController::class, 'index'])->name('shipments.index');
    Route::get('orders/{order}/shipments', [OrderShipmentController::class, 'index'])->whereNumber('order')->name('orders.shipments.index');
    Route::post('orders/{order}/shipments', [OrderShipmentController::class, 'store'])->whereNumber('order')->name('orders.shipments.store');
    Route::patch('shipments/{shipment}', [ShipmentController::class, 'update'])->whereNumber('shipment')->name('shipments.update');
    Route::post('shipments/{shipment}/dispatch', [ShipmentController::class, 'dispatch'])->whereNumber('shipment')->name('shipments.dispatch');
    Route::post('shipments/{shipment}/in-transit', [ShipmentController::class, 'inTransit'])->whereNumber('shipment')->name('shipments.in-transit');
    Route::post('shipments/{shipment}/delivered', [ShipmentController::class, 'delivered'])->whereNumber('shipment')->name('shipments.delivered');
    Route::post('shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])->whereNumber('shipment')->name('shipments.cancel');
    Route::post('shipments/{shipment}/assign-driver', [DeliveryAssignmentController::class, 'assign'])->whereNumber('shipment')->name('shipments.assign-driver');
    Route::patch('delivery-assignments/{assignment}/reassign', [DeliveryAssignmentController::class, 'reassign'])->whereNumber('assignment')->name('delivery-assignments.reassign');

    Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
    Route::post('drivers', [DriverController::class, 'store'])->name('drivers.store');
    Route::patch('drivers/{driver}', [DriverController::class, 'update'])->whereNumber('driver')->name('drivers.update');
    Route::delete('drivers/{driver}', [DriverController::class, 'destroy'])->whereNumber('driver')->name('drivers.destroy');
    Route::patch('drivers/{driver}/availability', [DriverController::class, 'availability'])->whereNumber('driver')->name('drivers.availability');
});

Route::middleware(['tenant.driver', 'module.notice:core'])->prefix('driver')->name('tenant.shipping.driver.')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile');
    Route::patch('availability', [ProfileController::class, 'availability'])->name('availability');
    Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::post('deliveries/{assignment}/pick-up', [DeliveryController::class, 'pickUp'])->whereNumber('assignment')->name('deliveries.pick-up');
    Route::post('deliveries/{assignment}/en-route', [DeliveryController::class, 'enRoute'])->whereNumber('assignment')->name('deliveries.en-route');
    Route::post('deliveries/{assignment}/delivered', [DeliveryController::class, 'delivered'])->whereNumber('assignment')->name('deliveries.delivered');
    Route::post('deliveries/{assignment}/failed', [DeliveryController::class, 'failed'])->whereNumber('assignment')->name('deliveries.failed');
});

Route::middleware(['tenant.storefront', 'module.notice:core'])->name('tenant.shipping.')->group(function (): void {
    Route::get('shipping/methods', [ShippingMethodController::class, 'index'])->name('methods.index');
    Route::get('orders/{order}/tracking', [TrackingController::class, 'show'])->whereNumber('order')->name('tracking');
});
