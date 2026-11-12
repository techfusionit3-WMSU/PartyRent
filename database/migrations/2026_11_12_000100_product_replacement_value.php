<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * products.replacement_value: what one unit costs to buy new today (set by the supplier).
 * Return inspection pre-fills damage charges from it and caps them at it; customers see it before booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('replacement_value', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('replacement_value'));
    }
};
