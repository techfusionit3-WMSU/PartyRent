<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a supplier operates from.
 *   location_type  shop  physical shop / warehouse: exact pin and address are public
 *                  home  home-based: only the city is public; the exact pin and address are shared
 *                        with a customer once their pickup request is accepted
 *                  none  no pickup location (online seller, delivery only); an optional pin is only
 *                        used as the dispatch point for distance-based delivery
 *   lat / lng      the pin (nullable)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('location_type', 8)->default('shop');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn(['location_type', 'lat', 'lng']));
    }
};
