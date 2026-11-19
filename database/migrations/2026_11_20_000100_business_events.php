<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Business (organization) suppliers are event companies, not item rental shops:
 *   event_packages  what they sell, priced per event (guest range, inclusions, photos)
 *   event_bookings  inquiry → quotation → booked → confirmed → completed (or declined / cancelled / expired)
 *   event_payments  the booking's payment schedule: reservation fee, deposit, balance (proof → business confirms)
 * Their old single-item listings and item packages are hidden from customers (set inactive, not deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::create('event_packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->text('description');
            $t->json('events')->nullable();
            $t->json('inclusions')->nullable();
            $t->json('photos')->nullable();
            $t->unsignedInteger('min_guests')->nullable();
            $t->unsignedInteger('max_guests')->nullable();
            $t->decimal('price', 12, 2);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('event_bookings', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique(); // EVT-10001
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('event_package_id')->nullable()->constrained()->nullOnDelete();
            // inquiry | quoted | booked | confirmed | completed | declined | cancelled | expired
            $t->string('status', 12)->default('inquiry');
            $t->string('event_type', 60);
            $t->date('event_date');
            $t->string('start_time', 5)->nullable();
            $t->string('end_time', 5)->nullable();
            $t->unsignedInteger('guests');
            $t->string('venue', 255);
            $t->string('city', 120)->nullable();
            $t->decimal('budget', 12, 2)->nullable();
            $t->text('notes')->nullable();
            $t->json('quote')->nullable(); // items, total, schedule, note, validUntil
            $t->timestamp('quoted_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('close_reason', 500)->nullable();
            $t->string('closed_by', 12)->nullable(); // customer | business | system
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
            $t->index(['customer_id', 'status']);
        });

        \Illuminate\Support\Facades\Schema::create('event_payments', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique(); // EVP-10001
            $t->foreignId('event_booking_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12); // reservation | deposit | balance
            $t->string('label', 60);
            $t->decimal('amount', 12, 2);
            $t->date('due_date');
            $t->string('status', 10)->default('due'); // due | pending | paid
            $t->string('method', 12)->nullable(); // bank | gcash | cash
            $t->string('reference', 80)->nullable();
            $t->string('proof_path')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('receipt_no', 20)->nullable()->unique();
            $t->string('reject_reason', 255)->nullable();
            $t->timestamps();
        });

        // Business shops stop renting single items: hide their old listings (kept, not deleted).
        $biz = DB::table('suppliers')->where('type', 'organization')->orWhere('account_type', 'business')->pluck('id');
        if ($biz->isNotEmpty()) {
            DB::table('products')->whereIn('supplier_id', $biz)->update(['status' => 'inactive']);
            DB::table('packages')->whereIn('supplier_id', $biz)->update(['active' => false]);
        }
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('event_payments');
        \Illuminate\Support\Facades\Schema::dropIfExists('event_bookings');
        \Illuminate\Support\Facades\Schema::dropIfExists('event_packages');
    }
};
