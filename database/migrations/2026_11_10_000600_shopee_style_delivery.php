<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopee-style delivery and payouts:
 *   payouts.delivery / voucher  delivery fee passed to the supplier with no commission, plus the
 *                               PartyRent-funded Free Shipping voucher amount
 *   products.weight_kg + size   for the PartyRent standard (weight-based) delivery rates
 *   suppliers.free_ship         joined the Free Shipping program (extra commission, vouchers for customers)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->decimal('delivery', 12, 2)->default(0)->after('commission');
            $table->decimal('voucher', 12, 2)->default(0)->after('delivery');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('length_cm', 7, 1)->nullable();
            $table->decimal('width_cm', 7, 1)->nullable();
            $table->decimal('height_cm', 7, 1)->nullable();
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('free_ship')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('payouts', fn (Blueprint $t) => $t->dropColumn(['delivery', 'voucher']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['weight_kg', 'length_cm', 'width_cm', 'height_cm']));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('free_ship'));
    }
};
