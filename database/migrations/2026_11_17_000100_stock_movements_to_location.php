<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference movement records show "From → To" for transfers between inventory locations:
 * stock_movements.inventory_location_id is the source, to_location_id the destination.
 * The type column grows to fit the reference movement kinds (write_off, transfer, repaired, initial…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignId('to_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $t->string('type', 16)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('to_location_id'));
    }
};
