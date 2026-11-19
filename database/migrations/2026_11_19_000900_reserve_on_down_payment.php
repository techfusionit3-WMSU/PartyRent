<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accepting a request no longer holds stock: the items are reserved when the first payment (down payment, or the
 * full payment) is verified (`reserved_at`). The customer has 24 hours from acceptance to pay it (`pay_by`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_requests', function (Blueprint $t) {
            $t->timestamp('pay_by')->nullable()->after('accepted_at');
            $t->timestamp('reserved_at')->nullable()->after('pay_by');
        });
        // requests accepted before this change keep their reservation
        DB::table('rental_requests')->whereIn('status', ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing', 'return_pending', 'inspection', 'completed'])
            ->update(['reserved_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('rental_requests', function (Blueprint $t) {
            $t->dropColumn(['pay_by', 'reserved_at']);
        });
    }
};
