<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\SuperController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Cashier\CashierController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\Customer\CartController;
use App\Http\Controllers\Customer\RentalController;
use App\Http\Controllers\Customer\StartSellingController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PaymentFileController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\Supplier\CatalogController;
use App\Http\Controllers\Supplier\DiscountController;
use App\Http\Controllers\Supplier\ReportController as SupplierReportController;
use App\Http\Controllers\Supplier\RequestController as SupplierRequestController;
use App\Http\Controllers\Supplier\SupplierController;
use Illuminate\Support\Facades\Route;

/* ---------- public marketplace ---------- */
Route::get('/', [MarketController::class, 'browse'])->name('home');
Route::get('/browse', [MarketController::class, 'browseRedirect'])->name('browse');
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
Route::get('/api/slots', [ApiController::class, 'slots'])->name('api.slots');
Route::post('/api/schedule', [ApiController::class, 'schedule'])->name('api.schedule');
Route::get('/api/locations', [ApiController::class, 'locations'])->name('api.locations');
Route::post('/api/package-quote', [ApiController::class, 'packageQuote'])->name('api.package-quote');
/* ---------- authentication ---------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/register/verify', [AuthController::class, 'showRegisterVerify'])->name('register.verify');
    Route::post('/register/verify', [AuthController::class, 'registerVerify'])->middleware('throttle:10,1,register-verify')->name('register.verify.submit');
    Route::post('/register/resend', [AuthController::class, 'registerResend'])->middleware('throttle:5,1,register-resend')->name('register.resend');
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
    Route::post('/reviews/{review}/report', [ReviewController::class, 'report'])->middleware('throttle:10,1')->name('reviews.report');
    Route::get('/payments/{payment}/proof', [PaymentFileController::class, 'proof'])->name('payments.proof');
    Route::get('/pay-accounts/{payAccount}/qr', [PaymentFileController::class, 'qr'])->name('payments.qr');

    // Email verification: unverified customers can browse, but renting/selling needs a verified email.
    Route::get('/email/verify', [AuthController::class, 'verifyNotice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1')->name('verification.send');
});

/* ---------- customer mode ---------- */
// Shortfall / dispute cases (change list C12): one page for the customer, the shop and PartyRent support.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/cases/{case}', [CaseController::class, 'show'])->name('cases.show');
    Route::post('/cases/{case}/propose', [CaseController::class, 'propose'])->name('cases.propose');
    Route::post('/cases/{case}/respond', [CaseController::class, 'respond'])->name('cases.respond');
    Route::post('/cases/{case}/message', [CaseController::class, 'message'])->name('cases.message');
    Route::post('/cases/{case}/settle', [CaseController::class, 'settle'])->name('cases.settle');
    Route::get('/cases-photo/{case}', [CaseController::class, 'photo'])->name('cases.photo');
});

Route::middleware(['auth', 'verified', 'role:customer'])->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart');
    Route::post('/cart', [CartController::class, 'add'])->name('cart.add');
    Route::post('/cart/select', [CartController::class, 'select'])->name('cart.select');
    Route::patch('/cart/{line}', [CartController::class, 'update'])->name('cart.update');
    Route::post('/cart/{line}/time', [CartController::class, 'time'])->name('cart.time');
    Route::delete('/cart/{line}', [CartController::class, 'remove'])->name('cart.remove');
    Route::get('/checkout', [CartController::class, 'checkout'])->name('checkout');
    Route::post('/checkout', [CartController::class, 'submit'])->name('checkout.submit');
    Route::post('/checkout/quote', [CartController::class, 'quote'])->name('checkout.quote');
    Route::get('/rentals/submitted', [CartController::class, 'submitted'])->name('rentals.submitted');

    Route::get('/rentals', [RentalController::class, 'index'])->name('rentals.index');
    Route::get('/rentals/{rentalRequest}', [RentalController::class, 'show'])->name('rentals.show');
    Route::get('/rentals/{rentalRequest}/submitted', [RentalController::class, 'confirmation'])->name('rentals.confirmation');
    Route::get('/rentals/{rentalRequest}/messages', [RentalController::class, 'messages'])->name('rentals.messages');
    Route::post('/rentals/{rentalRequest}/messages', [RentalController::class, 'sendMessage'])->middleware('throttle:30,1')->name('rentals.messages.send');
    Route::post('/rentals/{rentalRequest}/cancel', [RentalController::class, 'cancel'])->name('rentals.cancel');
    Route::post('/rentals/{rentalRequest}/installments', [RentalController::class, 'proposeInstallments'])->name('rentals.installments');
    Route::post('/rentals/{rentalRequest}/payments', [RentalController::class, 'pay'])->name('rentals.pay');
    Route::get('/rentals/{rentalRequest}/payments/{payment}/online', [RentalController::class, 'onlineReturn'])->name('rentals.online.return');
    // practice checkout (PAYMONGO_FAKE=true, never in production)
    Route::get('/practice-checkout/{ref}', [\App\Http\Controllers\Customer\PracticeCheckoutController::class, 'show'])->name('paymongo.practice');
    Route::post('/practice-checkout/{ref}', [\App\Http\Controllers\Customer\PracticeCheckoutController::class, 'act'])->name('paymongo.practice.act');
    Route::post('/rentals/{rentalRequest}/review', [RentalController::class, 'review'])->name('rentals.review');
    Route::get('/my-payments', [RentalController::class, 'payments'])->name('customer.payments');
    Route::get('/profile', [RentalController::class, 'profile'])->name('profile');

    Route::get('/start-selling', [StartSellingController::class, 'show'])->name('start-selling');
    Route::post('/start-selling', [StartSellingController::class, 'submit'])->name('start-selling.submit');
    Route::post('/start-selling/check', [StartSellingController::class, 'check'])->name('start-selling.check');
});

/* ---------- supplier mode ---------- */
Route::prefix('supplier')->name('supplier.')->middleware(['auth', 'verified', 'role:supplier'])->group(function () {
    Route::get('/', [SupplierController::class, 'dashboard'])->name('dashboard');
    Route::get('/schedule', [SupplierController::class, 'schedule'])->name('schedule');
    Route::get('/payments', [SupplierController::class, 'payments'])->name('payments');
    Route::post('/payments/{payment}/verify', [SupplierController::class, 'verifyPayment'])->name('payments.verify');
    Route::post('/payments/{payment}/reject', [SupplierController::class, 'rejectPayment'])->name('payments.reject');
    Route::get('/payouts', [\App\Http\Controllers\Supplier\PayoutController::class, 'index'])->name('payouts');
    Route::put('/payouts/account', [\App\Http\Controllers\Supplier\PayoutController::class, 'updateAccount'])->name('payouts.account');
    Route::get('/reviews', [ReviewController::class, 'supplierIndex'])->name('reviews');
    Route::post('/reviews/{review}/reply', [ReviewController::class, 'reply'])->name('reviews.reply');
    Route::get('/shop', [SupplierController::class, 'shop'])->name('shop');
    Route::match(['put', 'post'], '/shop', [SupplierController::class, 'updateShop'])->name('shop.update');
    Route::post('/shop/business', [\App\Http\Controllers\Supplier\StaffController::class, 'business'])->name('business');
    Route::get('/team', [\App\Http\Controllers\Supplier\TeamController::class, 'index'])->name('team');
    Route::post('/team', [\App\Http\Controllers\Supplier\TeamController::class, 'store'])->name('team.store');
    Route::put('/team/{user}', [\App\Http\Controllers\Supplier\TeamController::class, 'update'])->name('team.update');
    Route::redirect('/staff', '/supplier/team')->name('staff');

    Route::get('/requests', [SupplierRequestController::class, 'index'])->name('requests');
    Route::get('/requests/{rentalRequest}', [SupplierRequestController::class, 'show'])->name('requests.show');
    Route::get('/requests/{rentalRequest}/messages', [SupplierRequestController::class, 'messages'])->name('requests.messages');
    Route::post('/requests/{rentalRequest}/messages', [SupplierRequestController::class, 'sendMessage'])->middleware('throttle:30,1')->name('requests.messages.send');
    Route::post('/requests/{rentalRequest}/accept', [SupplierRequestController::class, 'accept'])->name('requests.accept');
    Route::post('/requests/{rentalRequest}/decline', [SupplierRequestController::class, 'decline'])->name('requests.decline');
    Route::post('/requests/{rentalRequest}/advance', [SupplierRequestController::class, 'advance'])->name('requests.advance');
    Route::post('/requests/{rentalRequest}/inspection', [SupplierRequestController::class, 'inspection'])->name('requests.inspection');
    Route::post('/requests/{rentalRequest}/settle', [SupplierRequestController::class, 'settle'])->name('requests.settle');
    Route::post('/requests/{rentalRequest}/complete', [SupplierRequestController::class, 'complete'])->name('requests.complete');
    Route::post('/requests/{rentalRequest}/notes', [SupplierRequestController::class, 'notes'])->name('requests.notes');
    Route::post('/requests/{rentalRequest}/remind', [SupplierRequestController::class, 'remind'])->middleware('throttle:10,1')->name('requests.remind');
    Route::post('/requests/{rentalRequest}/cash', [SupplierRequestController::class, 'recordCash'])->name('requests.cash');
    Route::post('/requests/{rentalRequest}/source', [SupplierRequestController::class, 'source'])->name('requests.source');
    Route::post('/requests/{rentalRequest}/schedule/{decision}', [SupplierRequestController::class, 'schedule'])->whereIn('decision', ['approve', 'reject'])->name('requests.schedule');

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
    Route::post('/packages/price', [CatalogController::class, 'packagePrice'])->name('packages.price');
    Route::post('/packages/preview', [CatalogController::class, 'previewPackage'])->name('packages.preview');
    Route::post('/packages/{package}/duplicate', [CatalogController::class, 'duplicatePackage'])->name('packages.duplicate');
    Route::post('/packages/{package}/move', [CatalogController::class, 'movePackage'])->name('packages.move');
    Route::post('/packages/{package}/active', [CatalogController::class, 'packageActive'])->name('packages.active');
    Route::delete('/packages/{package}', [CatalogController::class, 'deletePackage'])->name('packages.delete');
    Route::get('/shortfalls', [CaseController::class, 'supplierIndex'])->name('shortfalls');
    Route::post('/shortfalls', [CaseController::class, 'report'])->name('shortfalls.store');
    Route::get('/discounts', [DiscountController::class, 'index'])->name('discounts');
    Route::post('/discounts', [DiscountController::class, 'store'])->name('discounts.store');
    Route::put('/discounts/{discount}', [DiscountController::class, 'update'])->name('discounts.update');
    Route::post('/discounts/{discount}/active', [DiscountController::class, 'active'])->name('discounts.active');
    Route::delete('/discounts/{discount}', [DiscountController::class, 'destroy'])->name('discounts.delete');
    Route::put('/discounts/bulk/{product}', [DiscountController::class, 'bulk'])->name('discounts.bulk');
    Route::get('/inventory', [CatalogController::class, 'inventory'])->name('inventory');
    Route::post('/inventory/{product}/restock', [CatalogController::class, 'restock'])->name('inventory.restock');
    Route::post('/inventory/{product}/holds', [CatalogController::class, 'addHold'])->name('inventory.hold');
    Route::delete('/inventory/holds/{hold}', [CatalogController::class, 'releaseHold'])->name('inventory.release');
    Route::post('/inventory/{product}/condition', [CatalogController::class, 'adjustCondition'])->name('inventory.condition');
    Route::post('/inventory/{product}/stock', [CatalogController::class, 'locationStock'])->name('inventory.stock');
    Route::get('/locations', [\App\Http\Controllers\Supplier\LocationController::class, 'index'])->name('locations');
    Route::post('/locations', [\App\Http\Controllers\Supplier\LocationController::class, 'store'])->name('locations.store');
    Route::put('/locations/{location}', [\App\Http\Controllers\Supplier\LocationController::class, 'update'])->name('locations.update');
    Route::delete('/locations/{location}', [\App\Http\Controllers\Supplier\LocationController::class, 'destroy'])->name('locations.delete');
    Route::post('/locations/transfer/{product}', [\App\Http\Controllers\Supplier\LocationController::class, 'transfer'])->name('locations.transfer');

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
    Route::post('/applications/{supplier}/business', [AdminController::class, 'reviewBusiness'])->name('business.review');
    Route::get('/applications/{supplier}/business-doc', [AdminController::class, 'businessDoc'])->name('business.doc');
    Route::get('/applications/{supplier}/doc/{key}', [AdminController::class, 'applicationDoc'])->name('applications.doc');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/users/{user}/status', [AdminController::class, 'userStatus'])->name('users.status');
    Route::get('/transactions', [AdminController::class, 'transactions'])->name('transactions');
    Route::get('/payouts', [\App\Http\Controllers\Admin\PayoutController::class, 'index'])->name('payouts');
    Route::post('/payouts/release/{rentalRequest}', [\App\Http\Controllers\Admin\PayoutController::class, 'release'])->name('payouts.release');
    Route::post('/payouts/{payout}/pay', [\App\Http\Controllers\Admin\PayoutController::class, 'pay'])->name('payouts.pay');
    Route::post('/payouts/{payout}/send', [\App\Http\Controllers\Admin\PayoutController::class, 'send'])->name('payouts.send');
    Route::post('/payouts/{payout}/check', [\App\Http\Controllers\Admin\PayoutController::class, 'check'])->name('payouts.check');
    Route::get('/audit', [AdminController::class, 'audit'])->name('audit');
    Route::get('/activity', [AdminController::class, 'audit'])->name('activity');

    Route::get('/listings', [AdminController::class, 'listings'])->name('listings');
    Route::post('/listings/{product}/status', [AdminController::class, 'listingStatus'])->name('listings.status');
    Route::post('/packages/{package}/status', [AdminController::class, 'packageStatus'])->name('packages.status');
    Route::get('/categories', [AdminController::class, 'categories'])->name('categories');
    Route::post('/categories', [AdminController::class, 'storeCategory'])->name('categories.store');
    Route::post('/categories/{category}', [AdminController::class, 'updateCategory'])->name('categories.update');
    Route::get('/complaints', [AdminController::class, 'complaints'])->name('complaints');
    Route::get('/cases', [CaseController::class, 'adminIndex'])->name('cases');
    Route::post('/complaints', [AdminController::class, 'storeComplaint'])->name('complaints.store');
    Route::post('/complaints/{complaint}', [AdminController::class, 'updateComplaint'])->name('complaints.update');
    Route::get('/reviews', [ReviewController::class, 'adminIndex'])->name('reviews');
    Route::post('/reviews/{review}/moderate', [ReviewController::class, 'moderate'])->name('reviews.moderate');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    Route::get('/reports.csv', [AdminController::class, 'reportsCsv'])->name('reports.csv');
});
Route::prefix('superadmin')->name('super.')->middleware(['auth', 'role:superadmin'])->group(function () {
    Route::get('/', [SuperController::class, 'dashboard'])->name('dashboard');
    Route::get('/monitoring', [SuperController::class, 'monitoring'])->name('monitoring');
    Route::get('/users', [SuperController::class, 'users'])->name('users');
    Route::post('/users/{user}/status', [SuperController::class, 'userStatus'])->name('users.status');
    Route::get('/admins', [SuperController::class, 'admins'])->name('admins');
    Route::post('/admins', [SuperController::class, 'storeAdmin'])->name('admins.store');
    Route::post('/admins/{user}', [SuperController::class, 'updateAdmin'])->name('admins.update');
    Route::get('/roles', [SuperController::class, 'roles'])->name('roles');
    Route::post('/roles', [SuperController::class, 'updateRoles'])->name('roles.update');
    Route::get('/settings', [SuperController::class, 'settings'])->name('settings');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    Route::get('/audit', [AdminController::class, 'audit'])->name('audit');
    Route::post('/settings', [SuperController::class, 'updateSettings'])->name('settings.update');
    Route::get('/pay-accounts', [\App\Http\Controllers\Admin\PayAccountController::class, 'index'])->name('pay-accounts');
    Route::post('/pay-accounts', [\App\Http\Controllers\Admin\PayAccountController::class, 'store'])->name('pay-accounts.store');
    Route::post('/pay-accounts/{payAccount}', [\App\Http\Controllers\Admin\PayAccountController::class, 'update'])->name('pay-accounts.update');
    Route::post('/pay-accounts/{payAccount}/toggle', [\App\Http\Controllers\Admin\PayAccountController::class, 'toggle'])->name('pay-accounts.toggle');
    Route::post('/pay-accounts/{payAccount}/delete', [\App\Http\Controllers\Admin\PayAccountController::class, 'destroy'])->name('pay-accounts.delete');
});

/* ---------- Payment gateway webhooks (no session; checked by signature; CSRF exempt in bootstrap/app.php) ---------- */
Route::post('/webhooks/paymongo', \App\Http\Controllers\PayMongoWebhookController::class)->middleware('throttle:120,1')->name('webhooks.paymongo');
Route::post('/webhooks/xendit', \App\Http\Controllers\XenditWebhookController::class)->middleware('throttle:120,1')->name('webhooks.xendit');
