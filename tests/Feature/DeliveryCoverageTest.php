<?php

namespace Tests\Feature;

use App\Domain\Branches;
use App\Models\CartLine;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\ShopStaff;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delivery: the branch is assigned from the delivery address and each branch's delivery coverage (a distance, or listed
 * cities / barangays); one address for the whole checkout; structured address checked on the server.
 * Demo (tests keep Metro Manila): Elegant Occasion has Main Warehouse (Taguig), Branch A – Pasig, Branch B – Makati.
 */
class DeliveryCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private const PASIG = '137403000';

    private const TAGUIG = '137607000';

    private const DAVAO = '112402000';

    private Supplier $eo;

    private InventoryLocation $main;

    private InventoryLocation $pasig;

    private InventoryLocation $makati;

    private Product $chair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eo = Supplier::where('name', 'Elegant Occasion Rentals')->firstOrFail();
        [$this->main, $this->pasig, $this->makati] = $this->eo->branches()->get()->all();
        $this->chair = Product::where('supplier_id', $this->eo->id)->where('name', 'Gold Chiavari Chair')->firstOrFail();
    }

    private function day(): string
    {
        return add_days(today_iso(), 30);
    }

    private function dest(string $city, string $brgy = 'Kapitolyo', string $street = '25 Ortigas Ave. Ext.', array $x = []): array
    {
        return array_merge(['id' => 'd1', 'label' => 'Venue', 'zoneId' => '', 'addr' => ['cityCode' => $city, 'barangay' => $brgy, 'street' => $street]], $x);
    }

    private function order(array $dest, int $qty = 20, array $x = []): \Illuminate\Testing\TestResponse
    {
        $m = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($m)->withSession(['mode' => 'customer']);
        CartLine::where('user_id', $m->id)->delete();
        $this->post('/cart', ['type' => 'item', 'ref_id' => $this->chair->id, 'days' => json_encode([$this->day() => $qty])]);

        return $this->checkout($this->eo->id, array_merge(['contactName' => 'Maria', 'contactPhone' => '0917 555 0101', 'fulfillment' => 'delivery', 'destinations' => [$dest]], $x));
    }

    public function test_branch_comes_from_the_address_and_each_branch_coverage(): void
    {
        // Pasig branch: Pasig City, only Kapitolyo and Malinao; Main: Taguig only; Makati: within 50 km (default)
        $this->pasig->update(['coverage' => ['mode' => 'areas', 'areas' => [['city' => self::PASIG, 'barangays' => ['Kapitolyo', 'Malinao']]]]]);
        $this->main->update(['coverage' => ['mode' => 'areas', 'areas' => [['city' => self::TAGUIG, 'barangays' => []]]]]);
        $this->assertStringContainsString('Kapitolyo, Malinao', Branches::coverageText($this->pasig));

        $this->order($this->dest(self::PASIG, 'Kapitolyo'))->assertOk();
        $r = RentalRequest::latest('id')->firstOrFail();
        $this->assertSame($this->pasig->id, $r->branch_id);
        $this->assertStringContainsString('Brgy. Kapitolyo', $r->address); // the text address is built from the parts
        $this->assertSame(self::PASIG, $r->destinations[0]['cityCode']);

        // a Pasig barangay the Pasig branch does not list: Makati (distance) covers it instead; Main (Taguig only) never
        $opts = collect(Branches::options($this->eo, $r->lines, 'delivery', [$this->dest(self::PASIG, 'Rosario')]))->keyBy('id');
        $this->assertFalse($opts[$this->pasig->id]['serves']);
        $this->assertStringContainsString('does not deliver to Brgy. Rosario', $opts[$this->pasig->id]['note']);
        $this->assertFalse($opts[$this->main->id]['serves']);
        $this->assertTrue($opts[$this->makati->id]['serves']);

        // Taguig: only Main lists it, and Makati is within reach; Main has the chairs → Main (has everything first)
        $this->order($this->dest(self::TAGUIG, 'Fort Bonifacio', '5th Ave.'), 60)->assertOk();
        $this->assertSame($this->main->id, RentalRequest::latest('id')->first()->branch_id);
    }

    public function test_outside_every_branch_is_refused_with_the_reason_not_given_to_a_random_branch(): void
    {
        $before = RentalRequest::count();
        $res = $this->order($this->dest(self::DAVAO, 'Poblacion', '1 San Pedro St.'))->assertStatus(422);
        $this->assertStringContainsString('None of its branches delivers to Davao City', $res->json('error'));
        $this->assertSame($before, RentalRequest::count());
        // the same order for pickup is fine (the customer can still collect it)
        $this->order($this->dest(self::DAVAO), 20, ['fulfillment' => 'pickup', 'destinations' => []])->assertOk();
    }

    public function test_the_customer_cannot_choose_the_delivery_branch(): void
    {
        $this->pasig->update(['coverage' => ['mode' => 'areas', 'areas' => [['city' => self::PASIG, 'barangays' => []]]]]);
        // asking for Makati (which does not cover this address better) changes nothing
        $this->order($this->dest(self::PASIG), 20, ['branch_id' => $this->makati->id])->assertOk();
        $this->assertSame($this->pasig->id, RentalRequest::latest('id')->first()->branch_id);
        // a branch of another shop is never used either
        $other = InventoryLocation::where('supplier_id', '!=', $this->eo->id)->firstOrFail();
        $this->order($this->dest(self::PASIG), 20, ['branch_id' => $other->id])->assertOk();
        $this->assertSame($this->pasig->id, RentalRequest::latest('id')->first()->branch_id);
    }

    public function test_the_address_must_be_complete_and_locatable(): void
    {
        $this->assertStringContainsString('barangay', $this->order($this->dest(self::PASIG, ''))->assertStatus(422)->json('error'));
        $this->assertStringContainsString('house no. / street', $this->order($this->dest(self::PASIG, 'Kapitolyo', ''))->assertStatus(422)->json('error'));
        // a typed address with no known city and no pin: we cannot tell where it is
        $res = $this->order(['id' => 'd1', 'label' => 'Venue', 'zoneId' => '', 'address' => 'Blk 4 Lot 2, Somewhere Subdivision']);
        $this->assertStringContainsString('We cannot tell where', $res->assertStatus(422)->json('error'));
        // the same text with a map pin is fine
        $this->order(['id' => 'd1', 'label' => 'Venue', 'zoneId' => '', 'address' => 'Blk 4 Lot 2, Somewhere Subdivision', 'lat' => 14.586, 'lng' => 121.089])->assertOk();
    }

    public function test_one_address_serves_every_shop_in_the_checkout(): void
    {
        $m = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($m)->withSession(['mode' => 'customer']);
        CartLine::where('user_id', $m->id)->delete();
        $hub = Supplier::where('name', 'Celebration Hub Rentals')->firstOrFail();
        $other = Product::where('supplier_id', $hub->id)->where('listing_type', 'rent')->where('status', 'active')->firstOrFail();
        $this->post('/cart', ['type' => 'item', 'ref_id' => $this->chair->id, 'days' => json_encode([$this->day() => 10])]);
        $this->post('/cart', ['type' => 'item', 'ref_id' => $other->id, 'days' => json_encode([$this->day() => 2])]);
        $dest = $this->dest(self::PASIG);
        $orders = collect([$this->eo, $hub])->map(fn ($s) => ['supplier_id' => $s->id, 'cart_line_ids' => CartLine::where('user_id', $m->id)->where('supplier_id', $s->id)->pluck('id')->all(),
            'fulfillment' => 'delivery', 'destinations' => [$dest], 'plan' => 'full', 'eventDate' => $this->day()])->all();
        $q = $this->postJson(route('checkout.quote'), ['shared' => [], 'orders' => $orders])->assertOk()->json('orders');
        $this->assertCount(2, $q);
        foreach ($q as $o) {
            $this->assertTrue($o['ok'], (string) $o['error']);
            $this->assertNotNull($o['branchId']);
        }
        $this->get(route('checkout'))->assertOk()->assertSee('Delivery information')->assertSee('Special Instructions')
            ->assertSee('Provide any additional information or instructions for your delivery (e.g., nearby landmarks, gate details, or directions).', false)->assertDontSee('General Instructions');
    }

    /** Maria's cart: chairs from Happy Moments and from Celebration Hub (zone-priced shops), both delivered. */
    private function twoShops(): array
    {
        $m = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($m)->withSession(['mode' => 'customer']);
        CartLine::where('user_id', $m->id)->delete();
        $hm = Supplier::where('name', 'Happy Moments Party Rentals')->firstOrFail();
        $hub = Supplier::where('name', 'Celebration Hub Rentals')->firstOrFail();
        foreach ([$hm, $hub] as $s) {
            $s->update(['delivery' => array_merge((array) $s->delivery, ['mode' => 'zones'])]); // fee by zone
            $p = Product::where('supplier_id', $s->id)->where('listing_type', 'rent')->where('status', 'active')->where('package_only', false)->firstOrFail();
            $this->post('/cart', ['type' => 'item', 'ref_id' => $p->id, 'days' => json_encode([$this->day() => 2])]);
        }

        return [$m, $hm, $hub];
    }

    private function quote(User $m, array $shops, array $dests, array $x = []): array
    {
        $orders = array_map(fn ($s) => array_merge(['supplier_id' => $s->id, 'cart_line_ids' => CartLine::where('user_id', $m->id)->where('supplier_id', $s->id)->pluck('id')->all(),
            'fulfillment' => 'delivery', 'destinations' => $dests, 'plan' => 'full', 'eventDate' => $this->day()], $x[$s->id] ?? []), $shops);

        return collect($this->postJson(route('checkout.quote'), ['shared' => [], 'orders' => $orders])->assertOk()->json('orders'))->keyBy('supplier_id')->all();
    }

    public function test_zone_and_fee_come_from_the_address_for_each_store(): void
    {
        [$m, $hm, $hub] = $this->twoShops();
        // the same Pasig address: Celebration Hub has a "Pasig" zone, Happy Moments does not (its standard rate)
        $dest = $this->dest(self::PASIG, 'Kapitolyo') + ['zoneId' => 'z3']; // a zone sent by the browser is ignored
        $q = $this->quote($m, [$hm, $hub], [$dest]);
        $this->assertTrue($q[$hub->id]['ok'], (string) $q[$hub->id]['error']);
        $this->assertSame('Pasig', $q[$hub->id]['drops'][0]['zone']);
        $this->assertEquals(collect($hub->delivery_zones)->firstWhere('name', 'Pasig')['fee'], $q[$hub->id]['drops'][0]['fee']);
        $this->assertTrue($q[$hub->id]['drops'][0]['matched']);
        $this->assertSame('Other areas (standard rate)', $q[$hm->id]['drops'][0]['zone']);
        $this->assertFalse($q[$hm->id]['drops'][0]['matched']);
        $this->assertEquals((float) $hm->delivery_fee, $q[$hm->id]['drops'][0]['fee']);
        $this->assertNotNull($q[$hm->id]['branchName']);

        // the customer changes the address to Quezon City: zones and fees are worked out again
        $q2 = $this->quote($m, [$hm, $hub], [$this->dest('137404000', 'Kamuning', '23 Mabini St.')]);
        $this->assertSame('Quezon City', $q2[$hm->id]['drops'][0]['zone']);
        $this->assertTrue($q2[$hm->id]['drops'][0]['matched']);
        $this->assertFalse($q2[$hub->id]['drops'][0]['matched']);

        // submitted: the rental keeps the zone the system found, not the browser's
        $this->checkout($hub->id, ['contactName' => 'Maria', 'contactPhone' => '0917 555 0101', 'fulfillment' => 'delivery', 'destinations' => [$dest]])->assertOk();
        $r = RentalRequest::where('supplier_id', $hub->id)->latest('id')->firstOrFail();
        $this->assertSame(collect($hub->delivery_zones)->firstWhere('name', 'Pasig')['id'], $r->destinations[0]['zoneId']);
    }

    public function test_several_delivery_locations_each_get_their_own_zone_and_fee(): void
    {
        [$m, $hm, $hub] = $this->twoShops();
        $line = CartLine::where('user_id', $m->id)->where('supplier_id', $hm->id)->firstOrFail();
        $d1 = $this->dest('137404000', 'Kamuning', '23 Mabini St.') + ['id' => 'd1', 'label' => 'Home'];
        $d2 = array_merge($this->dest('137402000', 'Concepcion', '7 Gil Fernando Ave.'), ['id' => 'd2', 'label' => 'Reception']);
        // Happy Moments: one chair to each location; Celebration Hub: everything to the first one only
        $hmLines = [['cart_id' => $line->id, 'type' => 'item', 'days' => [$this->day() => 1], 'destId' => 'd1'], ['cart_id' => $line->id, 'type' => 'item', 'days' => [$this->day() => 1], 'destId' => 'd2']];
        $q = $this->quote($m, [$hm, $hub], [$d1, $d2], [$hm->id => ['lines' => $hmLines], $hub->id => ['destinations' => [$d1]]]);
        $this->assertTrue($q[$hm->id]['ok'], (string) $q[$hm->id]['error']);
        $drops = collect($q[$hm->id]['drops'])->keyBy('destId');
        $this->assertSame('Quezon City', $drops['d1']['zone']);
        $this->assertSame('Marikina / San Juan', $drops['d2']['zone']);
        $this->assertEquals($drops['d1']['fee'] + $drops['d2']['fee'], $q[$hm->id]['summary']['q']['deliveryFee']);
        $this->assertCount(1, $q[$hub->id]['drops']); // a store with nothing for the second location is not charged for it
    }

    public function test_branch_admins_manage_their_own_branch_coverage_only(): void
    {
        $u = User::where('email', 'desk@elegantoccasion.ph')->firstOrFail();
        ShopStaff::where('user_id', $u->id)->update(['role' => 'branch_admin', 'roles' => json_encode(['branch_admin']), 'branch_ids' => json_encode([$this->pasig->id])]);
        $this->actingAs($u->fresh())->withSession(['mode' => 'supplier']);
        $form = ['name' => $this->pasig->name, 'address' => 'Ortigas Ave. Ext., Pasig City', 'coverage' => ['mode' => 'areas', 'areas' => [['city' => 'Pasig City, Metro Manila', 'barangays' => 'Kapitolyo, Brgy. Ugong']]]];
        $this->get(route('supplier.locations.edit', $this->pasig))->assertOk()->assertSee('Delivery coverage')->assertSee('Only the areas I list');
        $this->get(route('supplier.locations.edit', $this->makati))->assertNotFound();
        $this->put(route('supplier.locations.update', $this->pasig), $form)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['mode' => 'areas', 'areas' => [['city' => self::PASIG, 'barangays' => ['Kapitolyo', 'Ugong']]]], $this->pasig->fresh()->coverage);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated branch']);
        // another branch: refused
        $this->put(route('supplier.locations.update', $this->makati), ['name' => $this->makati->name, 'address' => 'Pasong Tamo Ext., Makati City', 'coverage' => ['mode' => 'distance']])->assertNotFound();
        // areas must be real places
        $this->put(route('supplier.locations.update', $this->pasig), ['coverage' => ['mode' => 'areas', 'areas' => [['city' => 'Atlantis', 'barangays' => '']]]] + $form)->assertSessionHas('error');
        $this->assertSame(self::PASIG, $this->pasig->fresh()->coverage['areas'][0]['city']);
        // the owner sees every branch's coverage
        $this->actingAs($this->eo->owner)->get(route('supplier.locations'))->assertOk()->assertSee('Delivers: Pasig City (Kapitolyo, Ugong)');
    }
}
