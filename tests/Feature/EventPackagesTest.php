<?php

namespace Tests\Feature;

use App\Domain\Packages;
use App\Domain\Rentals;
use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Wedding-style packages: stages with time and venue, service teams per day, and tiers. */
class EventPackagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private Product $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = User::where('email', 'juan@demo.ph')->firstOrFail()->supplier;
        $this->item = Product::where('supplier_id', $this->s->id)->where('listing_type', 'rent')->where('status', 'active')->firstOrFail();
        $this->item->update(['total_stock' => 500, 'damaged' => 0, 'missing' => 0]);
    }

    private function def(array $over = []): array
    {
        return array_merge([
            'pricing' => ['mode' => 'fixed', 'price' => 50000],
            'stages' => [['name' => 'Ceremony', 'hint' => 'at the church'], ['name' => 'Reception', 'hint' => '']],
            'components' => [
                ['kind' => 'rent', 'productId' => $this->item->id, 'qty' => 2, 'stage' => 1],
                ['kind' => 'service', 'name' => 'Videographer', 'price' => 15000, 'qty' => 1, 'unit' => 'team', 'teamsPerDay' => 1, 'stage' => 0],
            ],
        ], $over);
    }

    private function save(array $over = [], array $data = []): Package
    {
        $res = Rentals::savePackage($this->s, array_merge(['name' => 'Garden Wedding', 'description' => 'Ceremony and reception, all set up.', 'events' => ['Wedding']], $this->def($over), $data));
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        return $res['package'];
    }

    public function test_stages_are_saved_and_described(): void
    {
        $k = $this->save();
        $def = Packages::def($k->fresh());
        $this->assertSame('Ceremony', $def['stages'][0]['name']);
        $this->assertSame(0, $def['components'][1]['stage']);
        $this->assertSame(1, $def['components'][1]['teamsPerDay']);

        $sel = Packages::cleanSel($def, ['stages' => [['time' => '14:00', 'venue' => 'St. Joseph Parish'], ['time' => 'bad', 'venue' => 'Garden Hall']]]);
        $this->assertSame(['time' => '14:00', 'venue' => 'St. Joseph Parish'], $sel['stages'][0]);
        $this->assertSame('', $sel['stages'][1]['time']);
        $lines = Packages::describe($def, $sel);
        $this->assertContains('Ceremony (2:00 PM, St. Joseph Parish): 1× Videographer', $lines);
        $this->assertTrue(collect($lines)->contains(fn ($l) => str_starts_with($l, 'Reception (Garden Hall): 2× ')));
    }

    public function test_bad_stages_and_team_limits_are_refused(): void
    {
        $this->assertStringContainsString('Stage 2 needs a name', Packages::validate($this->s, $this->def(['stages' => [['name' => 'Ceremony'], ['name' => '']]]))['error']);
        $bad = $this->def();
        $bad['components'][1]['qty'] = 2;
        $this->assertStringContainsString('teams per day', Packages::validate($this->s, $bad)['error']);
    }

    public function test_service_teams_per_day_block_double_booking(): void
    {
        $k = $this->save();
        $date = add_days(today_iso(), 20);
        $r = RentalRequest::where('status', 'accepted')->firstOrFail();
        $r->lines = [['id' => 'l1', 'type' => 'package', 'ref_id' => $k->id, 'days' => [$date => 1], 'snap' => Packages::snapshot($k), 'sel' => Packages::defaultSel(Packages::def($k))]];
        $r->saveQuietly();
        inv()->flush();

        $customer = User::where('email', 'jose@demo.ph')->firstOrFail();
        $res = Rentals::addToCart($customer, 'package', $k->id, [$date => 1]);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Videographer', $res['error']);

        inv()->flush();
        $this->assertTrue(Rentals::addToCart($customer, 'package', $k->id, [add_days($date, 1) => 1])['ok']);
    }

    public function test_tiers_show_a_switch(): void
    {
        $gold = $this->save([], ['tier_group' => 'Wedding', 'tier_label' => 'Gold']);
        $silver = $this->save(['pricing' => ['mode' => 'fixed', 'price' => 30000]], ['name' => 'Simple Wedding', 'tier_group' => 'Wedding', 'tier_label' => 'Silver']);
        $this->assertSame('Silver', $silver->fresh()->tier_label);

        $this->actingAs(User::where('email', 'jose@demo.ph')->firstOrFail())->get(route('packages.show', $gold))->assertOk()
            ->assertSee('Silver')->assertSee('Gold')->assertSee('Your event schedule')->assertSee('Ceremony · time');

        $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier'])->get(route('supplier.packages.edit', $gold))->assertOk()
            ->assertSee('Stages')->assertSee('Group name');
    }
}
