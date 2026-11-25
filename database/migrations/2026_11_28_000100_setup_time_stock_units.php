<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Allocated setup time (minutes) per rentable item and per package: how long before the rental start the items
 *   must arrive. Deliver by = rental start − the longest setup time of what goes to that location.
 * - The stock unit stays in products.unit ("piece / day", "set / hour", "pack"); custom units are allowed now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedSmallInteger('setup_minutes')->nullable()->after('buffer_min');
        });
        Schema::table('packages', function (Blueprint $t) {
            $t->unsignedSmallInteger('setup_minutes')->nullable()->after('max_hours');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('setup_minutes'));
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn('setup_minutes'));
    }
};
