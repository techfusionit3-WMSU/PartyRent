<?php

namespace Tests\Feature;

use App\Domain\Pricing;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supplier Discounts page (change list C11): shop discounts in pricing + bulk discounts per item. */
class DiscountsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function juan()
    {
        $u = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->actingAs($u)->withSession(['mode' => 'supplier']);

        return $u->supplier;
    }

    /** Quote of one rental line: $qty pieces on one date, 10 days ahead. */
    private function quote(Product $p, int $qty, ?string $at = null): array
    {
        return Pricing::quoteOrder(['supplier' => $p->supplier, 'fulfillment' => 'pickup', 'at' => $at,
            'lines' => [['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => [add_days(today_iso(), 10) => $qty]]]]);
    }

    public function test_shop_discounts_lower_the_quote(): void
    {
        $s = $this->juan();
        [$a, $b] = Product::where('supplier_id', $s->id)->where('listing_type', 'rent')->orderBy('id')->take(2)->get()->all();
        $this->get(route('supplier.discounts'))->assertOk()->assertSee('Shop discounts')->assertSee('Bulk discounts')->assertSee($a->name);

        $gross = $this->quote($a, 5)['rentalFee'];
        $this->post(route('supplier.discounts.store'), ['name' => 'Chair promo', 'kind' => 'percent', 'value' => 10, 'applies_to' => 'products', 'product_ids' => [$a->id]])
            ->assertSessionHas('success');
        $q = $this->quote($a, 5);
        $this->assertEqualsWithDelta($gross * 0.9, $q['rentalFee'], 0.05);
        $this->assertEqualsWithDelta($gross * 0.1, $q['discount'], 0.05);
        $this->assertSame('Chair promo', $q['discounts'][0]['name']);
        $this->assertEqualsWithDelta($q['rentalFee'], $q['total'], 0.001); // pickup, no services → total is net
        $this->assertSame(0.0, $this->quote($b, 5)['discount']); // other items untouched

        // the best of two applies (not both); ₱ amount is per piece and never more than the price
        $d2 = Discount::create(['supplier_id' => $s->id, 'name' => 'Big', 'kind' => 'amount', 'value' => 100000, 'applies_to' => 'all', 'active' => true]);
        $this->assertSame('Big', $this->quote($a, 5)['discounts'][0]['name']);
        $this->assertEqualsWithDelta(0, $this->quote($a, 5)['rentalFee'], 0.001);
        $d2->update(['value' => 1, 'min_qty' => 10]);
        $this->assertSame('Chair promo', $this->quote($a, 5)['discounts'][0]['name']); // below the minimum
        $this->assertSame(12.0, $this->quote($b, 12)['discount']); // ₱1 × 12 pieces
        $this->assertSame(0.0, $this->quote($b, 3)['discount']); // below the minimum of 10

        // period = the day the request is placed; turned off = gone for new quotes
        $d2->update(['starts_on' => add_days(today_iso(), 1)]);
        $this->assertSame(0.0, $this->quote($b, 12)['discount']);
        $this->assertSame(12.0, $this->quote($b, 12, add_days(today_iso(), 2))['discount']);
        $promo = Discount::where('name', 'Chair promo')->first();
        $this->post(route('supplier.discounts.active', $promo))->assertSessionHas('success');
        $this->assertFalse($promo->fresh()->active);
        $this->assertSame(0.0, $this->quote($a, 5)['discount']);

        // validation + ownership
        $this->post(route('supplier.discounts.store'), ['name' => 'Too much', 'kind' => 'percent', 'value' => 95, 'applies_to' => 'all'])->assertSessionHasErrors('value');
        $this->post(route('supplier.discounts.store'), ['name' => 'Xy', 'kind' => 'percent', 'value' => 5, 'applies_to' => 'products', 'product_ids' => []])->assertSessionHasErrors('product_ids');
        $other = Discount::create(['supplier_id' => Product::where('supplier_id', '!=', $s->id)->value('supplier_id'), 'name' => 'Theirs', 'kind' => 'percent', 'value' => 5, 'applies_to' => 'all']);
        $this->delete(route('supplier.discounts.delete', $other))->assertNotFound();
        $this->delete(route('supplier.discounts.delete', $d2))->assertSessionHas('success');
        $this->assertNull(Discount::find($d2->id));
    }

    /** C13: what checkout stores (and Xendit is asked to charge) is the discounted total. */
    public function test_checkout_stores_the_discounted_price(): void
    {
        $s = $this->juan();
        $p = Product::where('supplier_id', $s->id)->where('name', 'like', 'Rectangular%')->firstOrFail();
        Discount::create(['supplier_id' => $s->id, 'name' => 'Table week', 'kind' => 'percent', 'value' => 20, 'applies_to' => 'products', 'product_ids' => [$p->id], 'active' => true]);
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        \App\Models\CartLine::where('user_id', $maria->id)->delete();
        $d = add_days(today_iso(), 25);
        $this->actingAs($maria)->withSession(['mode' => 'customer'])
            ->post('/cart', ['type' => 'item', 'ref_id' => $p->id, 'days' => json_encode([$d => 2])])->assertSessionHas('success');
        $this->checkout($s->id, ['eventName' => 'Disc Party', 'eventType' => 'Birthday', 'eventDate' => $d, 'contactName' => 'Maria', 'contactPhone' => '0917 555 0101'])
            ->assertOk()->assertJsonPath('ok', true);
        $r = \App\Models\RentalRequest::where('event_name', 'Disc Party')->firstOrFail();
        $gross = 2 * Pricing::unitPrice($p, 2);
        $this->assertEqualsWithDelta($gross * 0.2, $r->pricing['discount'], 0.01);
        $this->assertEqualsWithDelta($gross * 0.8, $r->pricing['rentalFee'], 0.01);
        $this->get(route('rentals.show', $r))->assertOk()->assertSee('Shop discount')->assertSee('Table week');

        // turning the discount off later does not change the request's quoted price
        Discount::where('name', 'Table week')->update(['active' => false]);
        $this->assertEqualsWithDelta($gross * 0.8, $r->fresh()->pricing['rentalFee'], 0.01);
    }

    public function test_bulk_discount_editor(): void
    {
        $s = $this->juan();
        $p = Product::where('supplier_id', $s->id)->where('listing_type', 'rent')->orderBy('id')->firstOrFail();
        $this->put(route('supplier.discounts.bulk', $p), ['base_price' => 20, 'bulk' => [['min' => 100, 'price' => 15], ['min' => '', 'price' => '']]])->assertSessionHas('success');
        $this->assertEquals([['min' => 1, 'max' => 99, 'price' => 20], ['min' => 100, 'max' => null, 'price' => 15]], $p->fresh()->tiers);
        $this->assertSame('₱5 off per piece for orders of 100+', Pricing::bulkLine($p->fresh()));
        // a bulk price must be cheaper than the base; duplicate steps refused; removing all steps = base price only
        $this->put(route('supplier.discounts.bulk', $p), ['base_price' => 20, 'bulk' => [['min' => 10, 'price' => 25]]])->assertSessionHas('error');
        $this->put(route('supplier.discounts.bulk', $p), ['base_price' => 20, 'bulk' => [['min' => 10, 'price' => 15], ['min' => 10, 'price' => 12]]])->assertSessionHas('error');
        $this->put(route('supplier.discounts.bulk', $p), ['base_price' => 18])->assertSessionHas('success');
        $this->assertEquals([['min' => 1, 'max' => null, 'price' => 18]], $p->fresh()->tiers);
    }
}
