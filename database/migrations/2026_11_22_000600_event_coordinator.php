<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Event company roles (2026-11-22): a booking can be assigned to one Event Coordinator, who sees it on their desk. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_bookings', function (Blueprint $t) {
            $t->foreignId('coordinator_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_bookings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('coordinator_id');
        });
    }
};
