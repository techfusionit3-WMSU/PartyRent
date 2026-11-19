<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal shops: a purchase record per rental item (price paid, date, private receipt) that suggests the
 * replacement value lowered for age and condition, a reason when the value is set above the price paid,
 * and a fixed "minor damage" fee (cleaning, stain, small scratch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('purchase_price', 12, 2)->nullable()->after('replacement_value');
            $t->date('purchased_on')->nullable()->after('purchase_price');
            $t->string('purchase_receipt')->nullable()->after('purchased_on');
            $t->string('value_reason', 255)->nullable()->after('purchase_receipt');
            $t->decimal('minor_fee', 12, 2)->nullable()->after('value_reason');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['purchase_price', 'purchased_on', 'purchase_receipt', 'value_reason', 'minor_fee']);
        });
    }
};
