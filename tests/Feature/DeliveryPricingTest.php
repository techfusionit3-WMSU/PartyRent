<?php

namespace Tests\Feature;

use App\Domain\Locations;
use App\Domain\Pricing;
use App\Models\CartLine;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Nationwide delivery: locations dataset, zone/distance pricing, coverage, snapshots. */
class DeliveryPricingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private const QC = '137404000';
    private const MARIKINA = '137402000';
    private const CEBU_CITY = '072217000';
    private const TOLEDO = '072251000';

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function supplier(string $name): Supplier
    {
        return Supplier::where('name', 'like', $name.'%')->firstOrFail();
    }

    private function line(string $productName, string $supplierName, array $days): array
    {
        $p = Product::where('name', $productName)->where('supplier_id', $this->supplier($supplierName)->id)->firstOrFail();

        return ['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => $days];
    }

    /* ---------- locations dataset ---------- */

    public function test_locations_dataset_is_consistent(): void
    {
        $all = Locations::all();
        $codes = array_column($all, 'code');
        $this->assertSame(count($codes), count(array_unique($codes)), 'location codes must be unique');
        $this->assertCount(17, Locations::regions());
        foreach (Locations::cities() as $c) {
            $path = Locations::path($c['code']);
            $this->assertCount(3, $path, $c['name'].' should resolve to region → province → city');
            $this->assertSame('region', $path[0]['type']);
            $this->assertSame('province', $path[1]['type']);
            $this->assertNotNull($c['lat'], $c['name'].' needs a centroid');
        }
        foreach (['Cebu City', 'Davao City', 'Iloilo City', 'Bacolod City', 'Cagayan de Oro City', 'Baguio City', 'Quezon City', 'Angeles City', 'Malolos City', 'Bacoor City', 'Calamba City', 'Batangas City', 'Antipolo City'] as $n) {
            $this->assertNotNull(Locations::findCityByName($n), "$n missing from the starter dataset");
        }
        $this->assertSame(0.0, Locations::distanceKm(self::QC, self::QC));
        $km = Locations::distanceKm(self::CEBU_CITY, self::QC);
        $this->assertGreaterThan(700, $km); // ~570 km straight line × 1.3 road factor
        $this->assertSame('Quezon City, Metro Manila', Locations::label(self::QC));
    }

    /* ---------- 1. NCR customer + NCR supplier (zones) ---------- */

    public function test_ncr_customer_checks_out_with_ncr_supplier_and_snapshot_is_saved(): void
    {
        $maria = $this->user('maria@demo.ph');
        $shop = $this->supplier('Happy Moments');
        $table = Product::where('supplier_id', $shop->id)->where('name', 'like', 'Rectangular%')->first();
        $d1 = add_days(today_iso(), 25);
        CartLine::where('user_id', $maria->id)->delete();
        $this->actingAs($maria)->withSession(['mode' => 'customer'])
            ->post('/cart', ['type' => 'item', 'ref_id' => $table->id, 'days' => json_encode([$d1 => 2])])->assertSessionHas('success');

        // cart shows the estimate for her saved city; the checkout page and its live quote agree
        $this->get('/cart')->assertOk()->assertSee('Estimated delivery to');
        $this->get("/checkout/{$shop->id}")->assertOk()->assertSee('Delivery to Quezon City, Metro Manila')->assertSee('name="addr[cityCode]"', false);
        $this->get("/checkout/{$shop->id}?fulfillment=delivery&city=".self::CEBU_CITY)->assertOk()->assertSee('Delivery isn')->assertSee('id="submitReq"', false);
        $this->getJson("/checkout/{$shop->id}/quote?fulfillment=delivery&arrangement=full&city=".self::MARIKINA)->assertOk()->assertJson(['ok' => true]);

        $this->post('/checkout/'.$shop->id, [
            'eventName' => 'Zone Party', 'eventType' => 'Birthday', 'eventDate' => $d1, 'fulfillment' => 'delivery',
            'addr' => ['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => self::QC, 'barangay' => 'Kamuning', 'street' => '23 Mabini St.', 'landmark' => 'blue gate'],
            'contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101', 'arrangement' => 'full', 'method' => 'bank', 'agree' => '1',
        ])->assertRedirect();

        $r = RentalRequest::where('event_name', 'Zone Party')->firstOrFail();
        $this->assertSame(self::QC, $r->city_code);
        $this->assertSame('23 Mabini St., Brgy. Kamuning, Quezon City, Metro Manila (near blue gate)', $r->address);
        $this->assertEquals(500, $r->pricing['deliveryFee']);
        $this->assertTrue($r->pricing['delivery']['ok']);
        $this->assertSame('province', $r->pricing['delivery']['zone']['level']);
        $this->assertEquals(360 + 500, $r->pricing['total']);

        // later rate changes never touch the snapshot shown to staff
        $shop->update(['delivery' => array_merge($shop->deliverySettings(), ['zones' => [['level' => 'province', 'code' => '137000000', 'fee' => 9999]]])]);
        $this->assertEquals(500, $r->fresh()->fin()['pricing']['deliveryFee']);
        $this->assertEquals(860, $r->fresh()->fin()['payable']);
    }

    /* ---------- 2. Cebu customer + NCR supplier (out of coverage) ---------- */

    public function test_cebu_customer_cannot_get_delivery_from_ncr_supplier(): void
    {
        $tess = $this->user('tess@demo.ph');
        $shop = $this->supplier('Happy Moments');
        $chair = Product::where('supplier_id', $shop->id)->where('name', 'White Monoblock Chair')->first();
        $d1 = add_days(today_iso(), 25);
        $this->actingAs($tess)->withSession(['mode' => 'customer'])
            ->post('/cart', ['type' => 'item', 'ref_id' => $chair->id, 'days' => json_encode([$d1 => 10])])->assertSessionHas('success');

        $this->get('/cart')->assertOk()->assertSee('Pickup only in your area');
        $q = $this->getJson("/checkout/{$shop->id}/quote?fulfillment=delivery&arrangement=full&city=".self::CEBU_CITY)->assertOk()->json();
        $this->assertFalse($q['ok']);
        $this->assertStringContainsString('does not deliver to Cebu City', $q['deliveryHtml']);

        $this->post('/checkout/'.$shop->id, [
            'eventName' => 'Far Party', 'eventType' => 'Birthday', 'eventDate' => $d1, 'fulfillment' => 'delivery',
            'addr' => ['regionCode' => '070000000', 'provinceCode' => '072200000', 'cityCode' => self::CEBU_CITY, 'barangay' => 'Capitol Site', 'street' => '45 Osmeña Blvd.'],
            'contactName' => 'Tess Villamor', 'contactPhone' => '0927 555 1515', 'arrangement' => 'full', 'method' => 'bank', 'agree' => '1',
        ])->assertSessionHas('error');
        $this->assertNull(RentalRequest::where('event_name', 'Far Party')->first());

        // pickup still works
        $this->post('/checkout/'.$shop->id, [
            'eventName' => 'Far Party', 'eventType' => 'Birthday', 'eventDate' => $d1, 'fulfillment' => 'pickup',
            'contactName' => 'Tess Villamor', 'contactPhone' => '0927 555 1515', 'arrangement' => 'full', 'method' => 'bank', 'agree' => '1',
        ])->assertRedirect();
        $this->assertEquals(0, RentalRequest::where('event_name', 'Far Party')->firstOrFail()->pricing['deliveryFee']);
    }

    /* ---------- 3. distance mode at the maxKm edge ---------- */

    public function test_distance_mode_respects_max_km_edge(): void
    {
        $s = $this->supplier('Sugbo Fiesta'); // Cebu City shop pin; base 400 + 25/km after 5 km
        $km = Locations::distanceKmFrom($s->originCoords(), self::TOLEDO); // measured from the pin
        $this->assertNotEquals(Locations::distanceKm(self::CEBU_CITY, self::TOLEDO), $km);
        $at = Pricing::deliveryQuote($s, [], 0, self::TOLEDO, array_merge($s->deliverySettings(), ['maxKm' => $km]));
        $this->assertTrue($at['ok'], 'exactly at maxKm is deliverable');
        $this->assertEquals($km, $at['distanceKm']);
        $this->assertEquals(r2(400 + ($km - 5) * 25), $at['fee']);

        $past = Pricing::deliveryQuote($s, [], 0, self::TOLEDO, array_merge($s->deliverySettings(), ['maxKm' => $km - 0.1]));
        $this->assertFalse($past['ok']);
        $this->assertSame('out_of_coverage', $past['code']);

        $same = Pricing::deliveryQuote($s, [], 0, self::CEBU_CITY);
        $this->assertEquals(400, $same['fee']); // 0 km: base only
    }

    /* ---------- 4. free-delivery threshold ---------- */

    public function test_free_delivery_threshold_waives_trip_fee(): void
    {
        $s = $this->supplier('Party Essentials'); // Metro Manila 350, free over 5,000
        $below = Pricing::deliveryQuote($s, [], 4999.99, self::QC);
        $this->assertFalse($below['waived']);
        $this->assertEquals(350, $below['fee']);

        $at = Pricing::deliveryQuote($s, [], 5000, self::QC);
        $this->assertTrue($at['waived']);
        $this->assertEquals(0, $at['fee']);
        $this->assertEquals(0, array_sum(array_column($at['breakdown'], 'amount'))); // breakdown nets to the fee
    }

    /* ---------- 5. handling fee per unit ---------- */

    public function test_handling_fee_per_unit_is_added_even_when_delivery_is_free(): void
    {
        $s = $this->supplier('Sugbo Fiesta');
        $d = add_days(today_iso(), 30);
        $tent = $this->line('20×20 ft Fiesta Tent', 'Sugbo Fiesta', [$d => 2, add_days($d, 1) => 1]); // peak 2 units × ₱600
        $q = Pricing::deliveryQuote($s, [$tent], 0, self::CEBU_CITY);
        $this->assertEquals(1200, $q['handling']);
        $this->assertEquals(400 + 1200, $q['fee']);

        $free = Pricing::deliveryQuote($s, [$tent], 25000, self::CEBU_CITY); // over ₱20,000
        $this->assertTrue($free['waived']);
        $this->assertEquals(1200, $free['fee']);
        $this->assertEquals($free['fee'], r2(array_sum(array_column($free['breakdown'], 'amount'))));

        // per-line handling ignores quantity
        $stage = $this->line('Stage Platform (4×8 m)', 'Kapampangan', [$d => 2]);
        $this->assertEquals(1500, Pricing::deliveryQuote($this->supplier('Kapampangan'), [$stage], 0, '035401000')['handling']);
    }

    /* ---------- 6. multi-date request with chargePerTrip ---------- */

    public function test_charge_per_trip_counts_separate_date_blocks(): void
    {
        $s = $this->supplier('Elegant Occasion'); // Metro Manila 1,200, chargePerTrip on
        $d = add_days(today_iso(), 40);
        $chairs = $this->line('Gold Chiavari Chair', 'Elegant Occasion', [$d => 50, add_days($d, 1) => 50, add_days($d, 5) => 30]);
        $q = Pricing::deliveryQuote($s, [$chairs], 0, self::QC);
        $this->assertSame(2, $q['trips']);
        $this->assertEquals(2400, $q['fee']);

        $consecutive = $this->line('Gold Chiavari Chair', 'Elegant Occasion', [$d => 50, add_days($d, 1) => 50]);
        $this->assertSame(1, Pricing::deliveryQuote($s, [$consecutive], 0, self::QC)['trips']);

        $off = Pricing::deliveryQuote($s, [$chairs], 0, self::QC, array_merge($s->deliverySettings(), ['chargePerTrip' => false]));
        $this->assertSame(1, $off['trips']);
        $this->assertEquals(1200, $off['fee']);

        // full quote: the fee feeds the total and the policy amounts
        $full = Pricing::quote([$chairs], $s, 'delivery', 'down', self::QC);
        $this->assertEquals(r2($full['itemsSubtotal'] + 2400), $full['total']);
        $this->assertEquals(r2($full['total'] * 0.4), $full['downRequired']);
    }

    /* ---------- zone rules ---------- */

    public function test_most_specific_zone_wins_and_one_way_doubles(): void
    {
        $s = $this->supplier('Davao Celebrations'); // city 300 > Davao del Sur 800 > Region XI 2,000
        $this->assertEquals(300, $s->deliveryTo('112402000')['fee']);   // Davao City
        $this->assertEquals(800, $s->deliveryTo('112403000')['fee']);   // Digos (Davao del Sur)
        $this->assertEquals(2000, $s->deliveryTo('112511000')['fee']);  // Mati (Davao Oriental → region rate)
        $this->assertFalse($s->deliveryTo(self::QC)['ok']);

        $k = $this->supplier('Kapampangan'); // one-way fees: drop-off and retrieval both charged
        $q = $k->deliveryTo('035401000'); // Angeles City, Pampanga 500
        $this->assertSame(2, $q['legs']);
        $this->assertEquals(1000, $q['fee']);

        $this->assertSame('no_city', Pricing::deliveryQuote($s, [], 0, null)['code']);
        $this->assertSame('pickup_only', $this->supplier('Dream Events')->deliveryTo(self::QC)['code']);
    }

    /* ---------- supplier settings + preview ---------- */

    public function test_supplier_saves_distance_settings_and_previews_a_city(): void
    {
        $juan = $this->user('juan@demo.ph');
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $this->get('/supplier/shop')->assertOk()->assertSee('Delivery rates');

        $preview = $this->postJson('/api/delivery-preview', [
            'fulfillment' => 'both', 'shop_addr' => ['cityCode' => self::QC], 'city' => self::MARIKINA,
            'delivery' => ['mode' => 'distance', 'baseFee' => 200, 'perKm' => 10, 'freeKm' => 0, 'maxKm' => 30],
        ])->assertOk()->json();
        $this->assertTrue($preview['ok']);
        $this->assertStringStartsWith('Delivery to Marikina City, Metro Manila: ₱', $preview['text']);

        $far = $this->postJson('/api/delivery-preview', [
            'fulfillment' => 'both', 'shop_addr' => ['cityCode' => self::QC], 'city' => self::CEBU_CITY,
            'delivery' => ['mode' => 'distance', 'baseFee' => 200, 'perKm' => 10, 'maxKm' => 30],
        ])->json();
        $this->assertFalse($far['ok']);

        // zones mode with no areas is refused for a delivering shop
        $shop = $juan->supplier;
        $base = ['name' => $shop->name, 'description' => $shop->description, 'logo' => $shop->logo, 'contact_person' => $shop->contact_person, 'phone' => $shop->phone, 'email' => $shop->email, 'hours' => $shop->hours,
            'shop_addr' => $shop->address_parts, 'location_type' => $shop->location_type, 'lat' => $shop->lat, 'lng' => $shop->lng, 'fulfillment' => 'both', 'methods' => $shop->methods, 'arrangements' => $shop->arrangements,
            'reservationFee' => $shop->pol('reservationFee'), 'reservationCredited' => '1', 'downType' => 'percent', 'downValue' => 30, 'installments' => 3,
            'depositType' => 'fixed', 'depositValue' => 1000, 'cancelDays' => 3, 'cancellation' => 'x', 'returns' => 'x', 'conditions' => 'x'];
        $this->put('/supplier/shop', $base + ['delivery' => ['mode' => 'zones', 'zones' => []]])->assertSessionHasErrors('delivery.zones');
        $this->put('/supplier/shop', $base + ['delivery' => ['mode' => 'distance', 'baseFee' => 250, 'perKm' => 15, 'freeKm' => 3, 'maxKm' => 40, 'freeOver' => 10000, 'roundTrip' => '1', 'chargePerTrip' => '1']])
            ->assertSessionHas('success');
        $d = $shop->fresh()->delivery;
        $this->assertSame('distance', $d['mode']);
        $this->assertEquals(15, $d['perKm']);
        $this->assertTrue($d['chargePerTrip']);
    }

    /* ---------- browse badges / filter ---------- */

    public function test_browse_filters_by_deliver_to_city(): void
    {
        $this->get('/?to='.self::CEBU_CITY.'&delivery=1')->assertOk()
            ->assertSee('Sinulog Fiesta Backdrop')           // Cebu supplier delivers in Cebu City
            ->assertDontSee('Pink Balloon Garland Set');     // Quezon City supplier doesn't
        $this->get('/suppliers/'.$this->supplier('Happy Moments')->id)->assertOk()->assertSee('Pickup only in your area');
    }

    public function test_address_and_delivery_forms_render(): void
    {
        $this->get('/register')->assertOk()->assertSee('name="addr[regionCode]"', false);
        $this->assertContains([self::CEBU_CITY, 'Cebu City', 'city', '072200000', 10.3157, 123.8854], $this->getJson('/api/locations')->assertOk()->json());
        $tess = $this->user('tess@demo.ph');
        $this->actingAs($tess)->withSession(['mode' => 'customer']);
        $this->get('/start-selling')->assertOk()->assertSee('Delivery rates')->assertSee('name="shop_addr[cityCode]"', false)->assertSee('id="zoneTpl"', false);
        $this->get('/profile')->assertOk()->assertSee('Capitol Site');
        $this->actingAs($this->user('dodong@demo.ph'))->withSession(['mode' => 'supplier']);
        $this->get('/supplier/shop')->assertOk()->assertSee('By distance');
        $this->get('/supplier/products/new')->assertOk()->assertSee('Handling fee');
        $this->get('/supplier/requests/PR-0014')->assertOk()->assertSee('Handling: 20×20 ft Fiesta Tent');
    }

    public function test_profile_saves_structured_address(): void
    {
        $maria = $this->user('maria@demo.ph');
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->put('/account', [
            'name' => 'Maria Santos', 'email' => 'maria@demo.ph', 'phone' => '0917 555 0101',
            'addr' => ['regionCode' => '110000000', 'provinceCode' => '112400000', 'cityCode' => '112402000', 'barangay' => 'Bajada', 'street' => '1 Test St.'],
        ])->assertSessionHas('success');
        $maria->refresh();
        $this->assertSame('112402000', $maria->city_code);
        $this->assertSame('1 Test St., Brgy. Bajada, Davao City, Davao del Sur', $maria->address);

        // a city that is not in the list is rejected
        $this->put('/account', ['name' => 'Maria Santos', 'email' => 'maria@demo.ph', 'phone' => '0917 555 0101', 'addr' => ['regionCode' => 'x', 'provinceCode' => 'y', 'cityCode' => '999999999', 'barangay' => 'b', 'street' => 's']])
            ->assertSessionHasErrors('addr.cityCode');
    }
}
