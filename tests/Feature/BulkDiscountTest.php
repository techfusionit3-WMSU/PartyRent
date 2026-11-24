<?php

namespace Tests\Feature;

use App\Domain\Pricing;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bulk discount tiers: "from N pieces, X% off each" — rules, the supplier form, pricing and what customers see. */
class BulkDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function juanItem(): Product
    {
        $u = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->actingAs($u)->withSession(['mode' => 'supplier']);
        $p = Product::where('supplier_id', $u->supplier->id)->where('listing_type', 'rent')->where('rental_mode', '!=', 'hour')->orderBy('id')->firstOrFail();
        $p->update(['total_stock' => 100, 'tiers' => [['min' => 1, 'max' => null, 'price' => 100]]]);
        inv()->flush();

        return $p->fresh();
    }

    private function form(Product $p, array $bulk, float $base = 100): \Illuminate\Testing\TestResponse
    {
        return $this->put(route('supplier.products.update', $p), ['name' => $p->name, 'category_id' => $p->category_id, 'description' => $p->description ?: 'A test item for rent.',
            'total_stock' => 100, 'unit' => $p->unit, 'condition' => 'good_as_new', 'status' => 'active', 'base_price' => $base, 'bulk' => $bulk]);
    }

    private function quote(Product $p, array $days): array
    {
        return Pricing::quoteOrder(['supplier' => $p->supplier, 'fulfillment' => 'pickup',
            'lines' => [['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => $days]]]);
    }

    public function test_tier_rules(): void
    {
        $t = Pricing::tiersFrom(100, [['min' => 10, 'pct' => 10], ['min' => 5, 'pct' => 5], ['min' => 20, 'pct' => 15], ['min' => '', 'pct' => '']], 100);
        $this->assertEquals([
            ['min' => 1, 'max' => 4, 'price' => 100],
            ['min' => 5, 'max' => 9, 'price' => 95, 'pct' => 5],
            ['min' => 10, 'max' => 19, 'price' => 90, 'pct' => 10],
            ['min' => 20, 'max' => null, 'price' => 85, 'pct' => 15],
        ], $t);

        $err = fn (array $bulk, ?int $stock = null) => Pricing::tiersFrom(100, $bulk, $stock);
        $this->assertStringContainsString('Fill in both', $err([['min' => 5, 'pct' => '']]));
        $this->assertStringContainsString('2 or more', $err([['min' => 1, 'pct' => 5]]));
        $this->assertStringContainsString('different quantity', $err([['min' => 5, 'pct' => 5], ['min' => 5, 'pct' => 10]]));
        $this->assertStringContainsString('between 1% and 90%', $err([['min' => 5, 'pct' => 95]]));
        $this->assertStringContainsString('between 1% and 90%', $err([['min' => 5, 'pct' => 0]]));
        $this->assertStringContainsString('bigger discount', $err([['min' => 5, 'pct' => 10], ['min' => 10, 'pct' => 10]])); // overlapping: no gain for more
        $this->assertStringContainsString('bigger discount', $err([['min' => 5, 'pct' => 10], ['min' => 10, 'pct' => 5]]));
        $this->assertStringContainsString('never apply', $err([['min' => 150, 'pct' => 10]], 100));
        $this->assertStringContainsString('Up to 7', $err(array_map(fn ($i) => ['min' => $i * 2, 'pct' => $i * 5], range(1, 8))));
        $this->assertStringContainsString('between 1% and 90%', $err([['min' => 5, 'price' => 120]])); // an older price row above the base
    }

    public function test_product_form_saves_percent_tiers_and_follows_the_base_price(): void
    {
        $p = $this->juanItem();
        $this->get(route('supplier.products.edit', $p))->assertOk()->assertSee('data-bulk-editor', false)->assertSee('Add a discount');

        $this->form($p, [['min' => 5, 'pct' => 5], ['min' => 10, 'pct' => 10], ['min' => 20, 'pct' => 15]])->assertSessionHasNoErrors();
        $this->assertSame([95.0, 90.0, 85.0], array_map(fn ($t) => (float) $t['price'], array_slice($p->fresh()->tiers, 1)));
        $this->assertSame('5% off for 5+ pieces · up to 15% off at 20+', Pricing::bulkLine($p->fresh()));

        // the tiers keep their percent when the base price changes
        $this->form($p, [['min' => 5, 'pct' => 5], ['min' => 10, 'pct' => 10], ['min' => 20, 'pct' => 15]], 200)->assertSessionHasNoErrors();
        $this->assertSame([190.0, 180.0, 170.0], array_map(fn ($t) => (float) $t['price'], array_slice($p->fresh()->tiers, 1)));
        // the edit form shows the saved tiers as percentages
        $this->get(route('supplier.products.edit', $p))->assertSee('name="bulk[2][pct]" value="15"', false);

        // the old form accepted a "discount" price above the base price: refused now, nothing saved
        $this->form($p, [['min' => 5, 'price' => 150]])->assertSessionHasErrors('bulk');
        $this->form($p, [['min' => 5, 'pct' => 10], ['min' => 10, 'pct' => 5]])->assertSessionHasErrors('bulk');
        $this->form($p, [['min' => 500, 'pct' => 10]])->assertSessionHasErrors('bulk'); // above the 100 in stock
        $this->assertSame(170.0, (float) $p->fresh()->tiers[3]['price']);
    }

    public function test_quote_applies_the_reached_tier_once(): void
    {
        $p = $this->juanItem();
        $this->form($p, [['min' => 5, 'pct' => 5], ['min' => 10, 'pct' => 10], ['min' => 20, 'pct' => 15]])->assertSessionHasNoErrors();
        $p = $p->fresh();
        inv()->flush();
        $d1 = add_days(today_iso(), 10);
        $d2 = add_days($d1, 1);

        // 12 pieces → 10+ tier: ₱90 each; 4 pieces the next day → base ₱100
        $q = $this->quote($p, [$d1 => 12, $d2 => 4]);
        $this->assertSame(12 * 90.0 + 4 * 100.0, $q['rentalFee']);
        $this->assertSame(120.0, $q['bulkSaved']); // shown to the customer, already inside the Rental Fee
        $this->assertSame($q['rentalFee'], $q['total']);

        // two lines of the same item on one date share the tier (6 + 6 = 12 → ₱90), each line priced once
        $q2 = Pricing::quoteOrder(['supplier' => $p->supplier, 'fulfillment' => 'pickup', 'lines' => [
            ['id' => 'a', 'type' => 'item', 'ref_id' => $p->id, 'days' => [$d1 => 6]],
            ['id' => 'b', 'type' => 'item', 'ref_id' => $p->id, 'days' => [$d1 => 6]],
        ]]);
        $this->assertSame(1080.0, $q2['rentalFee']);

        // a shop discount works on the bulk price (₱90), not on the base price, and not twice
        Discount::create(['supplier_id' => $p->supplier_id, 'name' => 'Promo', 'kind' => 'percent', 'value' => 10, 'applies_to' => 'all', 'active' => true]);
        $q3 = $this->quote($p, [$d1 => 12]);
        $this->assertSame(108.0, $q3['discount']);
        $this->assertSame(972.0, $q3['rentalFee']);
    }

    public function test_customer_sees_the_discount(): void
    {
        $p = $this->juanItem();
        $this->form($p, [['min' => 5, 'pct' => 5], ['min' => 10, 'pct' => 10]])->assertSessionHasNoErrors();
        $p = $p->fresh();
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($maria)->withSession(['mode' => 'customer']);

        $this->get(route('products.show', $p))->assertOk()
            ->assertSee('5% off for 5+')->assertSee('data-bulk-prices', false)
            ->assertSee('data-min="10" data-pct="10" data-price="₱90.00"', false)
            ->assertSee('class="bulk-applied" data-bulk-prices hidden', false); // shown only once the quantity reaches a tier

        // the date rows show the price before the discount and the percent
        $d = add_days(today_iso(), 12);
        $rows = $this->postJson(route('api.schedule'), ['type' => 'item', 'ref_id' => $p->id, 'days' => [$d => 10]])->assertOk()->json('rows');
        $this->assertSame('10% bulk discount', $rows[0]['tier']);
        $this->assertSame(money(100), $rows[0]['wasText']);
        $this->assertSame(900.0, (float) $rows[0]['subtotal']);
        $rows = $this->postJson(route('api.schedule'), ['type' => 'item', 'ref_id' => $p->id, 'days' => [$d => 3]])->json('rows');
        $this->assertNull($rows[0]['tier']);

        // the Discounts page edits the same tiers as percentages
        $this->juanItem();
        $this->get(route('supplier.discounts'))->assertOk()->assertSee('data-bulk-editor', false);
    }
}
