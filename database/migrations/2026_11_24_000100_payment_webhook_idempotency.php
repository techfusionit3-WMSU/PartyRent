<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Webhook idempotency: the gateway's event id is stored once, so the same notification is never processed twice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $t) {
            $t->string('event_id', 120)->nullable()->unique()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $t) {
            $t->dropUnique(['event_id']);
            $t->dropColumn('event_id');
        });
    }
};
