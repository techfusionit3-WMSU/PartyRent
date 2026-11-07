<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PartyRent domain schema.
 *
 * Mirrors the prototype's data model. Rental lines are stored as JSON on the
 * request ({id, type, ref_id, days: {date: qty}}) because quantities can differ
 * per day and dates can be non-consecutive; availability is computed by
 * App\Domain\Inventory, which only scans requests whose date range covers the
 * day being checked (start_date/end_date are indexed for that).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name');
            $table->string('icon', 40)->default('gift');
            $table->string('grad_from', 9);
            $table->string('grad_to', 9);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('logo', 16)->default('🎈');
            $table->string('logo_path')->nullable();
            $table->string('color', 9)->default('#E11D74');
            $table->text('description');
            $table->string('city');
            $table->string('address');
            $table->string('phone', 40);
            $table->string('email');
            $table->string('contact_person')->default('');
            $table->string('hours')->default('');
            $table->string('fulfillment', 10)->default('both'); // delivery | pickup | both
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->json('methods');       // cod | bank | debit
            $table->json('arrangements');  // full | down | installment | reservation
            $table->json('policy');
            $table->decimal('rating', 3, 2)->default(0);
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | suspended
            $table->string('reject_reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('category_id', 40);
            $table->foreign('category_id')->references('id')->on('categories');
            $table->string('name');
            $table->text('description');
            $table->unsignedInteger('total_stock')->default(0);
            $table->unsignedInteger('damaged')->default(0);
            $table->unsignedInteger('missing')->default(0);
            $table->json('tiers'); // [{min, max|null, price}]
            $table->string('unit', 40)->default('piece / day');
            $table->string('condition', 40)->default('Excellent');
            $table->json('colors');
            $table->json('themes');
            $table->json('events');
            $table->json('images');
            $table->string('status', 12)->default('published'); // published | draft | hidden | archived
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('rent_count')->default(0);
            $table->boolean('delivery')->default(true);
            $table->boolean('pickup')->default(true);
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12); // maintenance | setaside
            $table->unsignedInteger('qty');
            $table->date('start_date');
            $table->date('end_date')->nullable(); // null = until released
            $table->string('note')->default('');
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('emoji', 16)->default('🎉');
            $table->text('description');
            $table->json('items'); // [{product_id, qty}]
            $table->decimal('price', 10, 2);
            $table->text('conditions');
            $table->json('events');
            $table->json('images');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('rental_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // PR-0001
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('supplier_id')->constrained();
            $table->string('event_name');
            $table->string('event_type', 40);
            $table->date('event_date');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('fulfillment', 10);
            $table->string('address')->default('');
            $table->string('contact_name')->default('');
            $table->string('contact_phone', 40)->default('');
            $table->text('instructions')->nullable();
            $table->json('lines');
            $table->string('arrangement', 12);
            $table->string('method', 10);
            $table->string('status', 20)->default('pending');
            $table->json('pricing'); // price snapshot taken at submission
            $table->text('supplier_notes')->nullable();
            $table->string('decline_reason')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->boolean('cancel_free')->nullable();
            $table->date('accepted_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->json('fulfillment_log')->nullable();
            $table->json('inspection')->nullable();
            $table->json('overdue_flags')->nullable();
            $table->timestamps();
            $table->index(['status', 'start_date', 'end_date']);
            $table->index(['supplier_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_request_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // TXN-10001
            $table->foreignId('rental_request_id')->constrained()->cascadeOnDelete();
            // reservation | down | installment | balance | full | deposit | deposit_refund | compensation | refund
            $table->string('type', 20);
            $table->string('method', 10); // cod | cash | bank | debit
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->string('reference', 80)->default('');
            $table->string('proof_path')->nullable();
            $table->string('proof_name')->nullable();
            $table->string('notes')->nullable();
            $table->string('status', 12)->default('pending'); // pending | verified | rejected
            $table->string('recorded_by', 12)->default('customer'); // customer | supplier | cashier
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('receipt_no', 20)->nullable()->unique();
            $table->string('reject_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'type']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rental_request_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment');
            $table->timestamps();
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // CMP-001
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('against_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('rental_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->string('status', 12)->default('open'); // open | in_review | resolved
            $table->string('priority', 8)->default('normal'); // low | normal | high
            $table->json('updates');
            $table->timestamps();
        });

        // "notifications" is reserved by Laravel's notification channel, so in-app alerts live here.
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // direct recipient
            $table->string('role', 12)->nullable(); // or broadcast to a staff role: admin | cashier | superadmin
            $table->string('scope', 12); // which mode it shows in: customer | supplier | cashier | admin | superadmin
            $table->string('title');
            $table->text('body');
            $table->string('link')->default('');
            $table->json('read_by');
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'scope']);
            $table->index('role');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 12)->default('system');
            $table->string('action');
            $table->text('details');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('cart_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8); // item | package
            $table->unsignedBigInteger('ref_id');
            $table->json('days');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['cart_lines', 'audit_logs', 'app_notifications', 'complaints', 'reviews', 'payments', 'request_events', 'rental_requests', 'packages', 'holds', 'products', 'suppliers', 'categories', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
