<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System update 2026-11-23: payment checking is automatic.
 *
 * payment_transactions records every message between PartyRent and the payment gateway: webhooks (with the
 * result of checking their signature / token), customers coming back from the checkout page, and the automatic
 * checks that ask the gateway about checkouts still open. Admins see it on Admin → Online Payments.
 * checked_at on payments / event_payments says when the gateway was last asked about an open checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('gateway', 20)->nullable();          // paymongo | xendit | practice
            $t->string('source', 20);                       // webhook | return | auto_check | manual_check | checkout
            $t->string('kind', 10)->default('rental');      // rental | event
            $t->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $t->foreignId('event_payment_id')->nullable()->constrained('event_payments')->nullOnDelete();
            $t->string('payment_code', 30)->nullable()->index(); // kept when the payment row is gone (discarded checkout)
            $t->string('order_code', 30)->nullable()->index();   // PR-… / EVB-…
            $t->string('gateway_ref', 80)->nullable();           // checkout session / invoice id
            $t->string('gateway_payment_id', 80)->nullable();    // the gateway's id of the money that moved
            $t->string('result', 20);                       // paid | review | failed | expired | rejected | error | ignored
            $t->boolean('verified')->default(false);        // the gateway's notification passed its signature / token check
            $t->decimal('amount', 12, 2)->nullable();
            $t->decimal('fee', 12, 2)->nullable();
            $t->string('channel', 40)->nullable();          // GCash, Maya, card…
            $t->string('message', 255)->nullable();
            $t->timestamps();
            $t->index(['result', 'created_at']);
        });

        Schema::table('payments', function (Blueprint $t) {
            $t->timestamp('checked_at')->nullable()->after('gateway_fee');
        });
        Schema::table('event_payments', function (Blueprint $t) {
            $t->timestamp('checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_payments', fn (Blueprint $t) => $t->dropColumn('checked_at'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('checked_at'));
        Schema::dropIfExists('payment_transactions');
    }
};
