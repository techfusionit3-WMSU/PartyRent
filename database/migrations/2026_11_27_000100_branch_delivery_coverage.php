<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery coverage per branch (App\Domain\Branches::covers): null / mode "distance" = within service_km of the
 * branch; mode "areas" = only the listed cities / municipalities (optionally only some barangays of each).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $t) {
            $t->json('coverage')->nullable()->after('service_km');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_locations', fn (Blueprint $t) => $t->dropColumn('coverage'));
    }
};
