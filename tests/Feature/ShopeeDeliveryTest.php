<?php

namespace Tests\Feature;

use App\Domain\Payouts;
use App\Domain\Pricing;
use App\Domain\Settings;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shopee-style delivery: weight-based standard rates, Free Shipping program, no commission on delivery. */
class ShopeeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private const QC = '137404000';
    private const MARIKINA = '137402000';
    private const ANGELES = '035401000';
    private const CEBU_CITY = '072217000';
    private const TOLEDO = '072251000';

    private function supplier(string $name): Supplier
    {
        return Supplier::where('name', 'like', $name.'%')->firstOrFail();
    }

    private function weightMode(Supplier $s, string $reach = 'inter'): array
    {
        return array_merge($s->deliverySettings(), ['mode' => 'weight', 'reach' => $reach, 'freeOver' => 0, 'roundTrip' => true, 'chargePerTrip' => false]);
    }

    public function test_route_tiers(): void
    {
        $this->assertSame('local', Pricing::routeTier(self::QC, self::MARIKINA));     // all of Metro Manila
        $this->assertSame('local', Pricing::routeTier(self::CEBU_CITY, self::TOLEDO)); // same province
        $this->assertSame('island', Pricing::routeTier(self::QC, self::ANGELES));      // Luzon
        $this->assertSame('inter', Pricing::routeTier(self::QC, self::CEBU_CITY));     // Luzon → Visayas
        $this->assertNull(Pricing::routeTier(null, self::QC));
    }

    public function test_weight_based_fee_uses_bigger_of_weight_and_size(): void
    {
        $s = $this->supplier('Party Essentials'); // Metro Manila
        $p = $s->products()->where('listing_type', 'rent')->where('delivery', true)->where('handling_fee', 0)->firstOrFail();
        $p->update(['weight_kg' => 3, 'length_cm' => null, 'width_cm' => null, 'height_cm' => null]);
        $line = ['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => [add_days(today_iso(), 20) => 10]];

        // 10 × 3 kg = 30 kg, same province: 80 first kg + 29 × 10
        $q = Pricing::deliveryQuote($s, [$line], 0, self::QC, $this->weightMode($s));
        $this->assertTrue($q['ok'], (string) $q['error']);
        $this->assertSame('local', $q['route']);
        $this->assertEquals(30, $q['weightKg']);
        $this->assertEquals(80 + 29 * 10, $q['fee']);
        $this->assertEquals($q['fee'], r2(array_sum(array_column($q['breakdown'], 'amount'))));

        // to Cebu (another island group): 180 + 29 × 35
        $this->assertEquals(180 + 29 * 35, Pricing::deliveryQuote($s, [$line], 0, self::CEBU_CITY, $this->weightMode($s))['fee']);

        // bulky but light: 50 × 50 × 70 cm ÷ 3500 = 50 kg per unit beats 3 kg
        $p->update(['length_cm' => 50, 'width_cm' => 50, 'height_cm' => 70]);
        inv()->flush();
        $this->assertEquals(500, Pricing::deliveryQuote($s, [$line], 0, self::QC, $this->weightMode($s))['weightKg']);

        // rates come from the superadmin settings
        Settings::set('shipFirstKg', ['local' => 100, 'island' => 150, 'inter' => 200]);
        Settings::set('shipPerKg', ['local' => 0, 'island' => 0, 'inter' => 0]);
        $this->assertEquals(100, Pricing::deliveryQuote($s, [$line], 0, self::QC, $this->weightMode($s))['fee']);
    }

    public function test_weight_mode_reach_limits_coverage(): void
    {
        $s = $this->supplier('Party Essentials');
        $this->assertTrue(Pricing::deliveryQuote($s, [], 0, self::MARIKINA, $this->weightMode($s, 'local'))['ok']);
        $out = Pricing::deliveryQuote($s, [], 0, self::ANGELES, $this->weightMode($s, 'local'));
        $this->assertSame('out_of_coverage', $out['code']);
        $this->assertTrue(Pricing::deliveryQuote($s, [], 0, self::ANGELES, $this->weightMode($s, 'island'))['ok']);
        $this->assertSame('out_of_coverage', Pricing::deliveryQuote($s, [], 0, self::CEBU_CITY, $this->weightMode($s, 'island'))['code']);
    }

    public function test_free_shipping_voucher_and_extra_commission(): void
    {
        $s = $this->supplier('Party Essentials'); // zones: Metro Manila 350, free over 5,000
        $s->update(['free_ship' => true]);
        $s->refresh();

        $below = Pricing::deliveryQuote($s, [], 999, self::QC);
        $this->assertEquals(0, $below['voucher']);
        $this->assertEquals(350, $below['fee']);

        $q = Pricing::deliveryQuote($s, [], 1000, self::QC); // min spend reached: up to ₱200 off
        $this->assertEquals(200, $q['voucher']);
        $this->assertEquals(150, $q['fee']);
        $this->assertEquals($q['fee'], r2(array_sum(array_column($q['breakdown'], 'amount'))));

        $waived = Pricing::deliveryQuote($s, [], 5000, self::QC); // supplier's own free delivery wins, no voucher needed
        $this->assertEquals(0, $waived['voucher']);
        $this->assertEquals(0, $waived['fee']);

        $p = $s->products()->where('listing_type', 'rent')->firstOrFail();
        $quote = Pricing::quote([['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => [add_days(today_iso(), 20) => 1]]], $s, 'pickup');
        $this->assertEquals(3, $quote['commissionExtraPct']);

        Settings::set('freeShipEnabled', false);
        $this->assertEquals(0, Pricing::deliveryQuote($s, [], 1000, self::QC)['voucher']);
        $this->assertFalse(Pricing::freeShipActive($s));
    }

    public function test_commission_is_not_taken_from_delivery(): void
    {
        $r = RentalRequest::where('status', 'completed')->whereHas('payments', fn ($q) => $q->where('status', 'verified'))->firstOrFail();
        $base = Payouts::breakdown($r);
        $this->assertGreaterThan(500, $base['income']);

        $r->pricing = array_merge($r->pricing, ['deliveryFee' => 500, 'delivery' => ['voucher' => 200], 'commissionExtraPct' => 3]);
        $r->finCache = null;
        $b = Payouts::breakdown($r);
        $this->assertEquals(500, $b['delivery']);
        $this->assertEquals(r2($b['income'] - 500), $b['items']);
        $this->assertEquals(8, $b['pct']);
        $this->assertEquals(r2($b['items'] * 0.08), $b['commission']);
        $this->assertEquals(200, $b['voucher']);
        $this->assertEquals(r2($b['items'] - $b['commission'] + 500 + 200 + $b['compensation']), $b['net']);
        $this->assertEquals(r2($b['income'] + 200 + $b['compensation']), Payouts::held($r));
    }

    public function test_pages_show_the_new_settings(): void
    {
        $owner = User::findOrFail($this->supplier('Party Essentials')->owner_id);
        $this->actingAs($owner)->withSession(['mode' => 'supplier']);
        $this->get('/supplier/shop')->assertOk()->assertSee('PartyRent standard rates')->assertSee('Join the Free Shipping program');
        $p = $this->supplier('Party Essentials')->products()->firstOrFail();
        $this->get('/supplier/products/'.$p->id.'/edit')->assertOk()->assertSee('Weight of one piece (kg)');
        $this->get('/supplier/payouts')->assertOk()->assertSee('You receive');

        $this->post('/api/delivery-preview', ['city' => self::CEBU_CITY, 'fulfillment' => 'both', 'shop_addr' => ['cityCode' => self::QC], 'delivery' => ['mode' => 'weight', 'reach' => 'inter']])
            ->assertOk()->assertJson(['ok' => true, 'fee' => 180]);
        $this->post('/api/delivery-preview', ['city' => self::CEBU_CITY, 'fulfillment' => 'both', 'shop_addr' => ['cityCode' => self::QC], 'delivery' => ['mode' => 'weight', 'reach' => 'local']])
            ->assertOk()->assertJson(['ok' => false]);

        $this->actingAs(User::where('email', 'super@partyrent.ph')->firstOrFail())->withSession(['mode' => 'superadmin']);
        $this->get('/superadmin/settings')->assertOk()->assertSee('PartyRent standard rates')->assertSee('Free Shipping program');
        $this->post('/superadmin/settings', [
            'platformName' => 'PartyRent', 'supportEmail' => 'support@partyrent.ph', 'supportPhone' => '(02) 8123 4567', 'overdueGraceDays' => 0, 'maxRentalDays' => 30, 'commissionPct' => 5,
            'shipFirstKg' => ['local' => 90, 'island' => 130, 'inter' => 190], 'shipPerKg' => ['local' => 12, 'island' => 22, 'inter' => 40],
            'shipVolDivisor' => 6000, 'shipDefaultKg' => 2, 'freeShipExtraPct' => 4, 'freeShipMinSpend' => 1500, 'freeShipCap' => 250,
        ])->assertSessionHas('success');
        Settings::flush();
        $this->assertEquals(['local' => 90, 'island' => 130, 'inter' => 190], Settings::get('shipFirstKg'));
        $this->assertEquals(6000, Settings::get('shipVolDivisor'));
        $this->assertFalse((bool) Settings::get('freeShipEnabled')); // unticked
    }

    public function test_free_shipping_badge_shows_for_members(): void
    {
        $s = $this->supplier('Party Essentials');
        $this->get(route('suppliers.show', $s))->assertOk()->assertDontSee('Free Shipping</span>', false);
        $s->update(['free_ship' => true]);
        $this->get(route('suppliers.index'))->assertOk()->assertSee('Free Shipping');
    }
}
