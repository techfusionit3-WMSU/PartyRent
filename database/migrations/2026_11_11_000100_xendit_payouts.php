<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier payouts sent through Xendit (Money-out) instead of a manual transfer.
 *   payouts.status       pending | sending (Xendit is processing) | paid
 *   payouts.gateway      xendit when sent through Xendit, null for a manual transfer
 *   payouts.gateway_ref  Xendit payout id
 *   payouts.failure      why the last Xendit attempt failed (the payout goes back to pending)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('gateway', 10)->nullable();
            $table->string('gateway_ref', 80)->nullable()->index();
            $table->string('failure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payouts', fn (Blueprint $t) => $t->dropColumn(['gateway', 'gateway_ref', 'failure']));
    }
};
