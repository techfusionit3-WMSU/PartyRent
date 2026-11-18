<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount kinds per item (percent / amount = ₱ per piece) and for the whole order (order_percent / order_amount),
 * with an optional minimum order amount (min_amount) for the order kinds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $t) {
            $t->string('kind', 16)->default('percent')->change();
            $t->decimal('min_amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('discounts', fn (Blueprint $t) => $t->dropColumn('min_amount'));
    }
};
