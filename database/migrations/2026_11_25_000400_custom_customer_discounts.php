<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom (private) discounts a shop gives one customer, e.g. family or a regular client.
 *
 *   discounts.customer_id        set = a custom discount for that customer only (never shown or applied to anyone else,
 *                                and never applied automatically like the shop's public promotions)
 *   discounts.max_uses           how many rentals it can be used on (null = no limit)
 *   discounts.note               private note for the shop, e.g. "My cousin"
 *   rental_requests.custom_discount_id   the custom discount applied to the rental. What was taken off (type, value,
 *                                amount, before / after totals, who applied it and when) is kept in the rental's
 *                                pricing snapshot, so later edits to the discount never change past rentals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->after('supplier_id')->constrained('users')->nullOnDelete();
            $t->unsignedSmallInteger('max_uses')->nullable()->after('min_qty');
            $t->string('note', 160)->nullable()->after('name');
            $t->index(['supplier_id', 'customer_id']);
        });
        Schema::table('rental_requests', function (Blueprint $t) {
            $t->foreignId('custom_discount_id')->nullable()->after('branch_id')->constrained('discounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('custom_discount_id'));
        Schema::table('discounts', function (Blueprint $t) {
            $t->dropIndex(['supplier_id', 'customer_id']);
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['max_uses', 'note']);
        });
    }
};
