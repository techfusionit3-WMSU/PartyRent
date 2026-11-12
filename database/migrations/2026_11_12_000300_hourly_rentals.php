<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rent by the hour.
 *   products.rental_mode   day (default) | hour — hourly items are booked for a start–end time on one date
 *   products.min_hours     shortest booking for hourly items
 *   products.buffer_min    gap kept free before/after each booking (travel, set-up, cleaning)
 *   cart_lines.time        {from: "HH:MM", to: "HH:MM"} for hourly lines (request lines carry the same key)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('rental_mode', 5)->default('day');
            $table->decimal('min_hours', 4, 1)->default(1);
            $table->unsignedSmallInteger('buffer_min')->default(60);
        });
        Schema::table('cart_lines', function (Blueprint $table) {
            $table->json('time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['rental_mode', 'min_hours', 'buffer_min']));
        Schema::table('cart_lines', fn (Blueprint $t) => $t->dropColumn('time'));
    }
};
