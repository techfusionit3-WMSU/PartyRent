<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event companies, part 2:
 *  - reviews.event_booking_id: one review per completed event booking (rates the business)
 *  - event_payments: paid online (gateway invoice or practice checkout) → money is with PartyRent until an admin
 *    sends it to the business (remitted_at / remitted_by)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $t) {
            $t->foreignId('event_booking_id')->nullable()->after('rental_request_id')->unique()->constrained()->nullOnDelete();
        });
        Schema::table('event_payments', function (Blueprint $t) {
            $t->string('gateway', 10)->nullable()->after('method'); // xendit | practice (null = transfer with proof)
            $t->string('gateway_ref', 120)->nullable()->after('gateway');
            $t->string('gateway_url', 500)->nullable()->after('gateway_ref');
            $t->timestamp('remitted_at')->nullable()->after('receipt_no');
            $t->foreignId('remitted_by')->nullable()->after('remitted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('remitted_by');
            $t->dropColumn(['gateway', 'gateway_ref', 'gateway_url', 'remitted_at']);
        });
        Schema::table('reviews', function (Blueprint $t) {
            $t->dropConstrainedForeignId('event_booking_id');
        });
    }
};
