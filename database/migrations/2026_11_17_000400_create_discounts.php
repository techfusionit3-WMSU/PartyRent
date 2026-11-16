<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change list C11: shop discounts managed on the supplier Discounts page (bulk discounts stay on the products'
 * tiers). kind percent = % off the item price; amount = ₱ off per piece (per day for rentals).
 * starts_on / ends_on = when a request must be placed to get it (null = no limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('kind', 10)->default('percent');
            $t->decimal('value', 10, 2);
            $t->string('applies_to', 10)->default('all');
            $t->json('product_ids')->nullable();
            $t->unsignedInteger('min_qty')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
