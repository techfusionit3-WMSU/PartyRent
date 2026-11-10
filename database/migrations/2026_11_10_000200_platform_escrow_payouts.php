<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace (escrow) money flow, like Shopee:
 *   customers pay PartyRent, PartyRent holds the money until the rental is completed, then releases
 *   a payout to the supplier: rental income − platform commission + damage compensation.
 *
 * payouts         one per completed (or cancelled-with-money-kept) rental request
 * suppliers.payout_*  where the supplier wants to receive payouts (GCash or bank)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('income', 12, 2);          // verified rental + sales payments − refunds
            $table->decimal('commission_pct', 5, 2);
            $table->decimal('commission', 12, 2);      // platform fee on income
            $table->decimal('compensation', 12, 2)->default(0); // damage compensation passed through (no fee)
            $table->decimal('net', 12, 2);             // what the supplier receives
            $table->string('status', 10)->default('pending'); // pending | paid
            $table->string('method', 10)->nullable();  // gcash | bank
            $table->string('reference', 80)->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('payout_method', 10)->nullable(); // gcash | bank
            $table->string('payout_bank', 60)->nullable();
            $table->string('payout_account_name', 120)->nullable();
            $table->string('payout_account_no', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['payout_method', 'payout_bank', 'payout_account_name', 'payout_account_no']);
        });
    }
};
