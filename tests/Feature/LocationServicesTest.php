<?php

namespace Tests\Feature;

use App\Domain\Geo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Location services shared by checkout and browsing (App\Domain\Geo, /api/geo/*): address search, the address at a
 * pin, caching, fallbacks when the map service is down, and the browsing location / stores map.
 * The map service is faked (no network in tests).
 */
class LocationServicesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private const PILAR = ['place_id' => 1, 'lat' => '6.9065', 'lon' => '122.0762', 'type' => 'building', 'name' => 'Pilar Building',
        'address' => ['house_number' => '5', 'road' => 'Pilar Street', 'quarter' => 'Zone IV', 'city' => 'Zamboanga City', 'state' => 'Zamboanga del Sur', 'region' => 'Zamboanga Peninsula', 'country_code' => 'ph']];

    protected function setUp(): void
    {
        parent::setUp();
        config(['partyrent.geocoder.enabled' => true, 'partyrent.geocoder.url' => 'https://geo.test']);
        Cache::flush();
    }

    public function test_search_turns_a_place_into_our_structured_address(): void
    {
        Http::fake(['geo.test/search*' => Http::response([self::PILAR])]);
        $r = $this->getJson(route('api.geo.search', ['q' => 'Pilar Street Zamboanga']))->assertOk()->json('results');
        $hit = $r[0];
        $this->assertSame('097332000', $hit['parts']['cityCode']);
        $this->assertSame('Zone IV', $hit['parts']['barangay']);
        $this->assertSame('Pilar Building, 5 Pilar Street', $hit['parts']['street']);
        $this->assertTrue($hit['precise']);
        $this->assertEqualsWithDelta(6.9065, $hit['lat'], 0.0001);
        // the same query again: answered from the cache, the service is asked once
        $this->getJson(route('api.geo.search', ['q' => 'Pilar Street Zamboanga']))->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'countrycodes=ph') && str_contains($req->header('User-Agent')[0] ?? '', 'PartyRent'));
    }

    public function test_city_search_still_works_when_the_service_is_down(): void
    {
        Http::fake(['geo.test/*' => Http::response('', 503)]);
        $d = $this->getJson(route('api.geo.search', ['q' => 'Zamboanga']))->assertOk()->json();
        $this->assertFalse($d['service']);
        $this->assertContains('Zamboanga City, Zamboanga del Sur', array_column($d['results'], 'label'));
        $this->assertFalse(collect($d['results'])->firstWhere('label', 'Zamboanga City, Zamboanga del Sur')['precise']);
    }

    public function test_the_address_at_a_pin_and_its_fallback(): void
    {
        Http::fake(['geo.test/reverse*' => Http::sequence()->push(self::PILAR)->pushStatus(500)]);
        $d = $this->getJson(route('api.geo.reverse', ['lat' => 6.90651, 'lng' => 122.07621]))->assertOk()->json();
        $this->assertSame('Zone IV', $d['parts']['barangay']);
        $this->assertEqualsWithDelta(6.90651, $d['lat'], 0.000001); // the customer's exact pin is kept
        // service down: the nearest city / municipality, marked not precise (the customer is asked to check)
        $d2 = Geo::reverse(6.9214, 122.079);
        $this->assertSame('097332000', $d2['parts']['cityCode']);
        $this->assertFalse($d2['precise']);
        // outside the country: refused
        $this->getJson(route('api.geo.reverse', ['lat' => 35.6, 'lng' => 139.7]))->assertStatus(422);
    }

    public function test_browsing_location_from_a_pin_and_the_stores_map(): void
    {
        $m = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($m)->withSession(['mode' => 'customer']);
        // current location / map pin only: its city is found on the server
        $qc = \App\Domain\Locations::get('137404000');
        $this->postJson(route('near.store'), ['lat' => $qc['lat'], 'lng' => $qc['lng']])->assertOk()->assertJsonPath('near.city', '137404000');
        $res = $this->get(route('suppliers.index', ['view' => 'map']))->assertOk()->assertSee('storesMap')->assertSee('Showing shops near');
        $pins = $res->viewData('pins');
        $this->assertNotEmpty($pins);
        $this->assertTrue(collect($pins)->every(fn ($p) => isset($p['url'], $p['shop'], $p['lat'])));
        $this->assertSame([round($qc['lat'], 2), round($qc['lng'], 2)], $res->viewData('center')); // only a rounded centre reaches the page
        // nearest first
        $list = $res->viewData('list');
        $kmOf = $res->viewData('kmOf');
        $this->assertLessThanOrEqual($kmOf($list[1]), $kmOf($list[0]));
        // a new place refreshes the shops: none near Davao City
        $this->postJson(route('near.store'), ['near' => ['cityCode' => '112402000'], 'lat' => 7.07, 'lng' => 125.61])->assertOk();
        $this->get(route('suppliers.index', ['view' => 'map']))->assertOk()->assertSee('No shops near here yet');
        $this->get(route('near'))->assertOk()->assertSee('nearMap')->assertSee('Use my current location');
    }
}
