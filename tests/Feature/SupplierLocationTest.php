<?php

namespace Tests\Feature;

use App\Models\CartLine;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shop pins, home-based privacy and sellers with no pickup location. */
class SupplierLocationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private const MARIKINA = ['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '137402000'];

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function application(array $over): array
    {
        return array_merge([
            // v4 wizard: an Individual Supplier with the required government ID
            'app' => ['type' => 'individual',
                'personal' => ['fullName' => 'Jose Garcia', 'birthDate' => '1990-04-02', 'address' => '7 Sampaguita St., Marikina Heights, Marikina', 'phone' => '0923 555 0707', 'email' => 'jose@demo.ph'],
                'identity' => ['idType' => 'Philippine National ID', 'idNumber' => '1234-5678-9012'], 'business' => ['productTypes' => 'speakers, lights'],
                'payout' => ['method' => 'bank', 'provider' => 'BDO', 'accountName' => 'Jose Garcia', 'accountNumber' => '001234567890']],
            'docs' => ['gov_id' => \Illuminate\Http\UploadedFile::fake()->create('id.pdf', 50, 'application/pdf')],
            'offerPlans' => ['full'], 'delivery_fee' => 300,
            'name' => 'Jose Sounds', 'description' => 'Sound systems and lights for parties in Marikina.', 'logo' => '🎵',
            'phone' => '0923 555 0707', 'email' => 'jose@demo.ph', 'hours' => 'Daily 8–6',
            'location_type' => 'shop', 'shop_addr' => self::MARIKINA + ['barangay' => 'Marikina Heights', 'street' => '7 Sampaguita St.'],
            'lat' => 14.6520, 'lng' => 121.1100, 'fulfillment' => 'both',
            'delivery' => ['mode' => 'zones', 'zones' => [['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '', 'fee' => 300]]],
            'methods' => ['cod'], 'arrangements' => ['full'], 'reservationFee' => 0, 'reservationCredited' => '1',
            'downType' => 'percent', 'downValue' => 0, 'installments' => 2, 'depositType' => 'fixed', 'depositValue' => 500, 'cancelDays' => 2,
            'cancellation' => 'Free up to 2 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.',
        ], $over);
    }

    public function test_online_seller_needs_no_street_or_pin_but_must_deliver(): void
    {
        $this->actingAs($this->user('jose@demo.ph'))->withSession(['mode' => 'customer']);
        // no pickup location + pickup offered → refused
        $this->post('/start-selling', $this->application(['location_type' => 'none', 'shop_addr' => self::MARIKINA, 'lat' => null, 'lng' => null]))
            ->assertSessionHasErrors('fulfillment');
        // delivery only: city is enough, no pin needed
        $this->post('/start-selling', $this->application(['location_type' => 'none', 'shop_addr' => self::MARIKINA, 'lat' => null, 'lng' => null, 'fulfillment' => 'delivery']))
            ->assertSessionHas('success');
        $s = Supplier::where('name', 'Jose Sounds')->firstOrFail();
        $this->assertSame('none', $s->location_type);
        $this->assertFalse($s->hasPin());
        $this->assertFalse($s->offersPickup());
        $this->assertSame('Online seller based in Marikina City, Metro Manila', $s->publicAddress());
    }

    public function test_pickup_needs_a_sensible_pin(): void
    {
        $this->actingAs($this->user('jose@demo.ph'))->withSession(['mode' => 'customer']);
        $this->post('/start-selling', $this->application(['lat' => null, 'lng' => null]))->assertSessionHasErrors('lat');          // pickup without a pin
        $this->post('/start-selling', $this->application(['lat' => 10.3157, 'lng' => 123.8854]))->assertSessionHasErrors('lat');  // pin in Cebu, city Marikina
        $this->post('/start-selling', $this->application(['lat' => 35.68, 'lng' => 139.76]))->assertSessionHasErrors('lat');      // outside the country
        $this->post('/start-selling', $this->application(['location_type' => 'home']))->assertSessionHas('success');
        $s = Supplier::where('name', 'Jose Sounds')->firstOrFail();
        $this->assertEqualsWithDelta(14.652, $s->lat, 0.00001);
        $this->assertSame('home', $s->location_type);
    }

    public function test_home_based_location_stays_private_until_pickup_is_accepted(): void
    {
        $happy = Supplier::where('name', 'like', 'Happy Moments%')->firstOrFail();
        $happy->update(['location_type' => 'home', 'lat' => 14.6401, 'lng' => 121.0772]); // the v4 seed has only public, unpinned shops
        $this->get("/suppliers/{$happy->id}")->assertOk()
            ->assertSee('Home-based in Quezon City')
            ->assertDontSee('88 Katipunan')
            ->assertDontSee((string) $happy->lat);

        // a public shop shows its address, pin and directions
        $dream = Supplier::where('name', 'like', 'Dream Events%')->firstOrFail();
        $dream->update(['lat' => 14.5547, 'lng' => 121.0144]); // pinned public shop
        $this->get("/suppliers/{$dream->id}")->assertOk()->assertSee('401 Chino Roces');
        $this->get("/suppliers/{$dream->id}?tab=policies")->assertOk()->assertSee('data-lat="'.$dream->lat.'"', false)->assertSee('Get directions');

        // PR-0002 is an accepted pickup from Happy Moments: the customer now gets the exact spot
        $kristine = $this->user('kristine@demo.ph');
        $this->actingAs($kristine)->withSession(['mode' => 'customer'])->get('/rentals/PR-0002')->assertOk()
            ->assertSee('88 Katipunan')->assertSee('data-lat="'.$happy->lat.'"', false);

        // a new, still pending pickup request does not reveal it
        $maria = $this->user('maria@demo.ph');
        $table = Product::where('supplier_id', $happy->id)->where('name', 'like', 'Rectangular%')->first();
        $d = add_days(today_iso(), 26);
        CartLine::where('user_id', $maria->id)->delete();
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->post('/cart', ['type' => 'item', 'ref_id' => $table->id, 'days' => json_encode([$d => 1])]);
        $this->get('/checkout')->assertOk()->assertDontSee('88 Katipunan');
        $this->checkout($happy->id, ['eventName' => 'Quiet Pickup', 'eventType' => 'Birthday', 'eventDate' => $d, 'fulfillment' => 'pickup',
            'contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101', 'arrangement' => 'full', 'method' => 'bank'])->assertOk()->assertJsonPath('ok', true);
        $code = RentalRequest::where('event_name', 'Quiet Pickup')->value('code');
        $this->get("/rentals/{$code}")->assertOk()->assertDontSee('88 Katipunan');
    }

    public function test_no_pickup_from_seller_without_a_pickup_location(): void
    {
        $hub = Supplier::where('name', 'like', 'Celebration Hub%')->firstOrFail();
        $hub->update(['location_type' => 'none', 'fulfillment' => 'delivery']); // online seller (not in the v4 seed)
        $maria = $this->user('maria@demo.ph');
        $this->actingAs($maria)->withSession(['mode' => 'customer']);
        // her seeded cart has Celebration Hub chairs; asking for pickup falls back to delivery
        $this->checkout($hub->id, ['eventName' => 'No Pickup', 'eventType' => 'Birthday', 'eventDate' => add_days(today_iso(), 12), 'fulfillment' => 'pickup',
            'contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101', 'arrangement' => 'full', 'method' => 'bank'])->assertStatus(422);
        $this->get("/suppliers/{$hub->id}")->assertOk()->assertSee('Online seller');
    }

    public function test_supplier_settings_page_has_map_picker(): void
    {
        $this->actingAs($this->user('juan@demo.ph'))->withSession(['mode' => 'supplier'])
            ->get('/supplier/shop')->assertOk()->assertSee('data-map-picker', false)->assertSee('Home-based')->assertSee('leaflet.js', false);
    }
}
