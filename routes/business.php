<?php

/*
 * Business suppliers (event companies): their own workspace, and the customer side of booking them.
 * Loaded from routes/web.php. Solo item-rental shops use the supplier.* routes instead.
 */

use App\Http\Controllers\Business\BookingController;
use App\Http\Controllers\Business\DashboardController;
use App\Http\Controllers\Business\PackageController;
use App\Http\Controllers\Business\SettingsController;
use App\Http\Controllers\Customer\EventController;
use Illuminate\Support\Facades\Route;

Route::prefix('business')->name('business.')->middleware(['auth', 'verified', 'role:supplier'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/calendar', [DashboardController::class, 'calendar'])->name('calendar');
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings');
    Route::get('/bookings/{eventBooking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::post('/bookings/{eventBooking}/quote', [BookingController::class, 'quote'])->name('bookings.quote');
    Route::post('/bookings/{eventBooking}/decline', [BookingController::class, 'decline'])->name('bookings.decline');
    Route::post('/bookings/{eventBooking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::post('/bookings/{eventBooking}/complete', [BookingController::class, 'complete'])->name('bookings.complete');
    Route::get('/payments', [BookingController::class, 'payments'])->name('payments');
    Route::post('/payments/{eventPayment}/confirm', [BookingController::class, 'confirmPayment'])->name('payments.confirm');
    Route::get('/payments/{eventPayment}/proof', [BookingController::class, 'proof'])->name('payments.proof');
    Route::post('/payments/{eventPayment}/remind', [BookingController::class, 'remind'])->name('payments.remind');
    Route::get('/event-types', [\App\Http\Controllers\Business\EventTypeController::class, 'index'])->name('event-types');
    Route::post('/event-types/request', [\App\Http\Controllers\Business\EventTypeController::class, 'request'])->middleware('throttle:10,1')->name('event-types.request');
    Route::post('/event-types/{eventType}', [\App\Http\Controllers\Business\EventTypeController::class, 'apply'])->middleware('throttle:20,1')->name('event-types.apply');
    Route::get('/event-types/proof/{row}/{which}/{i}', [\App\Http\Controllers\Business\EventTypeController::class, 'file'])->whereIn('which', ['photo', 'doc'])->whereNumber('i')->name('event-types.file');
    Route::get('/packages', [PackageController::class, 'index'])->name('packages');
    Route::get('/packages/new', [PackageController::class, 'create'])->name('packages.create');
    Route::post('/packages', [PackageController::class, 'store'])->name('packages.store');
    Route::get('/packages/{eventPackage}/edit', [PackageController::class, 'edit'])->name('packages.edit');
    Route::put('/packages/{eventPackage}', [PackageController::class, 'update'])->name('packages.update');
    Route::post('/packages/{eventPackage}/toggle', [PackageController::class, 'toggle'])->name('packages.toggle');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

/* PartyRent admins: all event bookings, and online event payments to send on to the businesses */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:admin,superadmin'])->group(function () {
    Route::get('/events', [\App\Http\Controllers\Admin\EventBookingController::class, 'index'])->name('events');
    Route::post('/events/payments/{eventPayment}/sent', [\App\Http\Controllers\Admin\EventBookingController::class, 'remit'])->name('events.remit');
});

/* customers booking a business */
Route::middleware('auth')->group(function () {
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/events/{eventBooking}', [EventController::class, 'show'])->name('events.show');
    Route::get('/suppliers/{supplier}/book', [EventController::class, 'create'])->name('events.create');
    Route::post('/suppliers/{supplier}/book', [EventController::class, 'store'])->middleware('throttle:10,1')->name('events.store');
    Route::post('/events/{eventBooking}/accept', [EventController::class, 'accept'])->name('events.accept');
    Route::post('/events/{eventBooking}/cancel', [EventController::class, 'cancel'])->name('events.cancel');
    Route::post('/events/{eventBooking}/payments/{eventPayment}', [EventController::class, 'pay'])->middleware('throttle:10,1')->name('events.pay');
    Route::get('/events/{eventBooking}/payments/{eventPayment}/proof', [EventController::class, 'proof'])->name('events.proof');
    Route::post('/events/{eventBooking}/payments/{eventPayment}/online', [EventController::class, 'payOnline'])->middleware('throttle:10,1')->name('events.online');
    Route::get('/events/{eventBooking}/payments/{eventPayment}/online', [EventController::class, 'onlineReturn'])->name('events.online.return');
    Route::get('/events/{eventBooking}/payments/{eventPayment}/practice', [EventController::class, 'practice'])->name('events.online.practice');
    Route::post('/events/{eventBooking}/payments/{eventPayment}/practice', [EventController::class, 'practicePay'])->name('events.online.practice.pay');
    Route::post('/events/{eventBooking}/review', [EventController::class, 'review'])->middleware('throttle:10,1')->name('events.review');
});
