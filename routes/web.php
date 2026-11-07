<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Cashier\CashierController;
use App\Http\Controllers\Customer\CartController;
use App\Http\Controllers\Customer\RentalController;
use App\Http\Controllers\Customer\StartSellingController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PaymentFileController;
use App\Http\Controllers\Supplier\CatalogController;
use App\Http\Controllers\Supplier\ReportController as SupplierReportController;
use App\Http\Controllers\Supplier\RequestController as SupplierRequestController;
use App\Http\Controllers\Supplier\SupplierController;
use Illuminate\Support\Facades\Route;

/* ---------- public marketplace ---------- */
Route::get('/', [MarketController::class, 'home'])->name('home');
Route::get('/browse', [MarketController::class, 'browse'])->name('browse');
Route::get('/suppliers', [MarketController::class, 'suppliers'])->name('suppliers.index');
Route::get('/suppliers/{supplier}', [MarketController::class, 'supplier'])->name('suppliers.show');
Route::get('/products/{product}', [MarketController::class, 'product'])->name('products.show');
Route::get('/products/{product}/alternatives', [MarketController::class, 'alternatives'])->name('products.alternatives');
Route::get('/packages', [MarketController::class, 'packages'])->name('packages.index');
Route::get('/packages/{package}', [MarketController::class, 'package'])->name('packages.show');
Route::get('/ai', [AiController::class, 'planner'])->name('ai');
Route::post('/ai/plan', [AiController::class, 'plan'])->name('ai.plan');
Route::post('/ai/chat', [AiController::class, 'chat'])->middleware('throttle:30,1')->name('ai.chat');

/* ---------- live availability / pricing (JSON, read-only) ---------- */
Route::get('/api/calendar', [ApiController::class, 'calendar'])->name('api.calendar');
Route::post('/api/schedule', [ApiController::class, 'schedule'])->name('api.schedule');

/* ---------- authentication ---------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendReset'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->name('password.update');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

/* ---------- any signed-in user ---------- */
Route::middleware('auth')->group(function () {
    Route::post('/mode', [AccountController::class, 'switchMode'])->name('mode.switch');
    Route::get('/notifications', [AccountController::class, 'notifications'])->name('notifications');
    Route::post('/notifications/read', [AccountController::class, 'markRead'])->name('notifications.read');
    Route::get('/notifications/{notification}/open', [AccountController::class, 'openNotification'])->name('notifications.open');
    Route::get('/account', [AccountController::class, 'account'])->name('account');
    Route::put('/account', [AccountController::class, 'updateProfile'])->name('account.update');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::get('/payments/{payment}/receipt', [PaymentFileController::class, 'receipt'])->name('payments.receipt');
    Route::get('/payments/{payment}/proof', [PaymentFileController::class, 'proof'])->name('payments.proof');
});

/* ---------- customer mode ---------- */
Route::middleware(['auth', 'role:customer'])->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart');
    Route::post('/cart', [CartController::class, 'add'])->name('cart.add');
    Route::patch('/cart/{line}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/cart/{line}', [CartController::class, 'remove'])->name('cart.remove');
    Route::get('/checkout/{supplier}', [CartController::class, 'checkout'])->name('checkout');
    Route::post('/checkout/{supplier}', [CartController::class, 'submit'])->name('checkout.submit');

    Route::get('/rentals', [RentalController::class, 'index'])->name('rentals.index');
    Route::get('/rentals/{rentalRequest}', [RentalController::class, 'show'])->name('rentals.show');
    Route::get('/rentals/{rentalRequest}/submitted', [RentalController::class, 'confirmation'])->name('rentals.confirmation');
    Route::post('/rentals/{rentalRequest}/cancel', [RentalController::class, 'cancel'])->name('rentals.cancel');
    Route::post('/rentals/{rentalRequest}/payments', [RentalController::class, 'pay'])->name('rentals.pay');
    Route::post('/rentals/{rentalRequest}/review', [RentalController::class, 'review'])->name('rentals.review');
    Route::get('/my-payments', [RentalController::class, 'payments'])->name('customer.payments');
    Route::get('/profile', [RentalController::class, 'profile'])->name('profile');

    Route::get('/start-selling', [StartSellingController::class, 'show'])->name('start-selling');
    Route::post('/start-selling', [StartSellingController::class, 'submit'])->name('start-selling.submit');
});

/* ---------- supplier mode ---------- */
Route::prefix('supplier')->name('supplier.')->middleware(['auth', 'role:supplier'])->group(function () {
    Route::get('/', [SupplierController::class, 'dashboard'])->name('dashboard');
    Route::get('/schedule', [SupplierController::class, 'schedule'])->name('schedule');
    Route::get('/payments', [SupplierController::class, 'payments'])->name('payments');
    Route::get('/shop', [SupplierController::class, 'shop'])->name('shop');
    Route::put('/shop', [SupplierController::class, 'updateShop'])->name('shop.update');

    Route::get('/requests', [SupplierRequestController::class, 'index'])->name('requests');
    Route::get('/requests/{rentalRequest}', [SupplierRequestController::class, 'show'])->name('requests.show');
    Route::post('/requests/{rentalRequest}/accept', [SupplierRequestController::class, 'accept'])->name('requests.accept');
    Route::post('/requests/{rentalRequest}/decline', [SupplierRequestController::class, 'decline'])->name('requests.decline');
    Route::post('/requests/{rentalRequest}/advance', [SupplierRequestController::class, 'advance'])->name('requests.advance');
    Route::post('/requests/{rentalRequest}/inspection', [SupplierRequestController::class, 'inspection'])->name('requests.inspection');
    Route::post('/requests/{rentalRequest}/settle', [SupplierRequestController::class, 'settle'])->name('requests.settle');
    Route::post('/requests/{rentalRequest}/complete', [SupplierRequestController::class, 'complete'])->name('requests.complete');
    Route::post('/requests/{rentalRequest}/notes', [SupplierRequestController::class, 'notes'])->name('requests.notes');
    Route::post('/requests/{rentalRequest}/cash', [SupplierRequestController::class, 'recordCash'])->name('requests.cash');

    Route::get('/products', [CatalogController::class, 'products'])->name('products');
    Route::get('/products/new', [CatalogController::class, 'createProduct'])->name('products.create');
    Route::post('/products', [CatalogController::class, 'storeProduct'])->name('products.store');
    Route::get('/products/{product}/edit', [CatalogController::class, 'editProduct'])->name('products.edit');
    Route::put('/products/{product}', [CatalogController::class, 'updateProduct'])->name('products.update');
    Route::post('/products/{product}/status', [CatalogController::class, 'productStatus'])->name('products.status');
    Route::get('/packages', [CatalogController::class, 'packages'])->name('packages');
    Route::get('/packages/new', [CatalogController::class, 'createPackage'])->name('packages.create');
    Route::post('/packages', [CatalogController::class, 'storePackage'])->name('packages.store');
    Route::get('/packages/{package}/edit', [CatalogController::class, 'editPackage'])->name('packages.edit');
    Route::put('/packages/{package}', [CatalogController::class, 'updatePackage'])->name('packages.update');
    Route::get('/inventory', [CatalogController::class, 'inventory'])->name('inventory');
    Route::post('/inventory/{product}/holds', [CatalogController::class, 'addHold'])->name('inventory.hold');
    Route::delete('/inventory/holds/{hold}', [CatalogController::class, 'releaseHold'])->name('inventory.release');
    Route::post('/inventory/{product}/condition', [CatalogController::class, 'adjustCondition'])->name('inventory.condition');

    Route::get('/calendar', [SupplierReportController::class, 'calendar'])->name('calendar');
    Route::get('/damages', [SupplierReportController::class, 'damages'])->name('damages');
    Route::get('/reports', [SupplierReportController::class, 'reports'])->name('reports');
    Route::get('/reports.csv', [SupplierReportController::class, 'reportsCsv'])->name('reports.csv');
});

/* ---------- cashier ---------- */
Route::prefix('cashier')->name('cashier.')->middleware(['auth', 'role:cashier,superadmin'])->group(function () {
    Route::get('/', [CashierController::class, 'dashboard'])->name('dashboard');
    Route::get('/verification', [CashierController::class, 'verification'])->name('verification');
    Route::post('/payments/{payment}/verify', [CashierController::class, 'verify'])->name('verify');
    Route::post('/payments/{payment}/reject', [CashierController::class, 'reject'])->name('reject');
    Route::get('/record', [CashierController::class, 'record'])->name('record');
    Route::post('/record', [CashierController::class, 'storeRecord'])->name('record.store');
    Route::get('/balances', [CashierController::class, 'balances'])->name('balances');
    Route::get('/history', [CashierController::class, 'history'])->name('history');
    Route::get('/reports', [CashierController::class, 'reports'])->name('reports');
    Route::get('/reports.csv', [CashierController::class, 'reportsCsv'])->name('reports.csv');
});

/* ---------- admin & superadmin ---------- */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:admin,superadmin'])->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/applications', [AdminController::class, 'applications'])->name('applications');
    Route::post('/applications/{supplier}', [AdminController::class, 'reviewApplication'])->name('applications.review');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/users/{user}/status', [AdminController::class, 'userStatus'])->name('users.status');
    Route::get('/transactions', [AdminController::class, 'transactions'])->name('transactions');
    Route::get('/audit', [AdminController::class, 'audit'])->name('audit');

    Route::get('/listings', [AdminController::class, 'listings'])->name('listings');
    Route::post('/listings/{product}/status', [AdminController::class, 'listingStatus'])->name('listings.status');
    Route::post('/packages/{package}/status', [AdminController::class, 'packageStatus'])->name('packages.status');
    Route::get('/categories', [AdminController::class, 'categories'])->name('categories');
    Route::post('/categories', [AdminController::class, 'storeCategory'])->name('categories.store');
    Route::post('/categories/{category}', [AdminController::class, 'updateCategory'])->name('categories.update');
    Route::get('/complaints', [AdminController::class, 'complaints'])->name('complaints');
    Route::post('/complaints', [AdminController::class, 'storeComplaint'])->name('complaints.store');
    Route::post('/complaints/{complaint}', [AdminController::class, 'updateComplaint'])->name('complaints.update');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    Route::get('/reports.csv', [AdminController::class, 'reportsCsv'])->name('reports.csv');
});
Route::get('/superadmin', [AdminController::class, 'dashboard'])->middleware(['auth', 'role:superadmin'])->name('superadmin.dashboard');
