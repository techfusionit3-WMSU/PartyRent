<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Consumables are SOLD, not rented.
 *    products.listing_type  rent | sale. For sale items `total_stock` is the stock on hand: it is not
 *                           date-based and goes down permanently when an order is released.
 *    perishable, lead_days (order at least N days ahead), shelf_life_days, low_stock_at (alert threshold)
 *    stock_movements        audit trail of sold / restock / adjust movements
 *
 * 2. Suppliers build their own packages.
 *    packages.components    [{kind: rent|sale|service|addon|choice, ...}] (see App\Domain\Packages)
 *    packages.pricing       {mode: fixed|sum|sum_discount, price, discountPct}
 *    packages.min_guests / max_guests, sort, is_draft
 *    `items` and `price` stay as a denormalised summary (included products, headline price) for
 *    listings, search and the AI planner.
 *    cart_lines.sel         the customer's package choices / add-ons / guest count
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('listing_type', 4)->default('rent');
            $table->boolean('perishable')->default(false);
            $table->unsignedSmallInteger('lead_days')->default(0);
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->unsignedInteger('low_stock_at')->nullable();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('qty'); // signed: sold < 0, restock > 0
            $table->string('type', 10); // sold | restock | adjust
            $table->foreignId('rental_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('balance'); // stock on hand after the movement
            $table->string('note')->default('');
            $table->timestamp('created_at')->nullable();
            $table->index(['product_id', 'created_at']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->json('components')->nullable();
            $table->json('pricing')->nullable();
            $table->unsignedInteger('min_guests')->nullable();
            $table->unsignedInteger('max_guests')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_draft')->default(false);
        });

        Schema::table('cart_lines', fn (Blueprint $table) => $table->json('sel')->nullable());

        // Existing packages: every item becomes an included rental component at the old fixed daily price.
        foreach (DB::table('packages')->get(['id', 'items', 'price']) as $i => $k) {
            $items = json_decode((string) $k->items, true) ?: [];
            DB::table('packages')->where('id', $k->id)->update([
                'components' => json_encode(array_map(fn ($it) => ['kind' => 'rent', 'productId' => (int) $it['product_id'], 'qty' => (int) $it['qty'], 'required' => true], $items)),
                'pricing' => json_encode(['mode' => 'fixed', 'price' => (float) $k->price, 'discountPct' => 0]),
                'sort' => $i,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('cart_lines', fn (Blueprint $table) => $table->dropColumn('sel'));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn(['components', 'pricing', 'min_guests', 'max_guests', 'sort', 'is_draft']));
        Schema::dropIfExists('stock_movements');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['listing_type', 'perishable', 'lead_days', 'shelf_life_days', 'low_stock_at']));
    }
};
