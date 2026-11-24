<?php

namespace Tests\Feature;

use App\Domain\Branches;
use App\Models\CartLine;
use App\Models\InventoryLocation;
use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-branch suppliers: one public store, several branches, each with its own stock. Demo: Elegant Occasion
 * (BGC main warehouse, Pasig branch, Makati branch; delivery priced by distance from the branch).
 *   Gold Chiavari Chair: BGC 100, Pasig 50, Makati 0 · Crystal Centerpiece: BGC 20, Makati 10
 *   Wedding Reception Package: 100 chairs, backdrop, 12 centerpieces, 12 cloths, uplights (all only at BGC)
 */
class BranchesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private InventoryLocation $bgc;

    private InventoryLocation $pasig;

    private InventoryLocation $makati;

    private Product $chair;

    /** A delivery address pinned next to the Pasig branch. */
    private const PASIG_ADDR = ['id' => 'd1', 'label' => 'Venue', 'address' => '25 Ortigas Ave. Ext., Pasig City', 'zoneId' => '', 'lat' => 14.5860, 'lng' => 121.0890];

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Supplier::where('name', 'Elegant Occasion Rentals')->firstOrFail();
        [$this->bgc, $this->pasig, $this->makati] = $this->s->branches()->get()->all();
        $this->chair = Product::where('supplier_id', $this->s->id)->where('name', 'Gold Chiavari Chair')->firstOrFail();
    }

    private function day(int $n = 40): string
    {
        return add_days(today_iso(), $n);
    }

    private function asCustomer(string $email = 'maria@demo.ph'): User
    {
        $u = User::where('email', $email)->firstOrFail();
        CartLine::where('user_id', $u->id)->delete();
        $this->actingAs($u)->withSession(['mode' => 'customer']);

        return $u;
    }

    private function rent(int $productId, int $qty, ?string $date = null, string $type = 'item'): void
    {
        $this->post('/cart', ['type' => $type, 'ref_id' => $productId, 'days' => json_encode([$date ?? $this->day() => $qty])])->assertSessionHas('success');
    }

    private function delivery(array $x = []): array
    {
        return array_merge(['contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101', 'eventDate' => $this->day(), 'fulfillment' => 'delivery', 'destinations' => [self::PASIG_ADDR]], $x);
    }

    public function test_branches_are_one_store_with_separate_stock(): void
    {
        $this->assertSame(['Main Warehouse', 'Branch A – Pasig', 'Branch B – Makati'], [$this->bgc->name, $this->pasig->name, $this->makati->name]);
        $this->assertTrue($this->bgc->is_main);
        $this->assertSame([100, 50, 0], [inv()->stockAt($this->chair->id, $this->bgc->id), inv()->stockAt($this->chair->id, $this->pasig->id), inv()->stockAt($this->chair->id, $this->makati->id)]);
        // customers see one store, not one per branch
        $this->asCustomer();
        $this->get(route('suppliers.index'))->assertOk()->assertSee('Elegant Occasion Rentals')->assertDontSee('Branch A – Pasig');
        $this->get(route('products.show', $this->chair))->assertOk()->assertSee('Elegant Occasion Rentals');
    }

    public function test_recommends_the_nearest_branch_with_stock_and_prices_delivery_from_it(): void
    {
        $this->asCustomer();
        $this->rent($this->chair->id, 40);
        $lines = [['id' => 'l1', 'type' => 'item', 'ref_id' => $this->chair->id, 'days' => [$this->day() => 40]]];
        $opts = collect(Branches::options($this->s, $lines, 'delivery', [self::PASIG_ADDR]))->keyBy('id');

        // Pasig is closest and has 50 chairs; BGC has them too but is farther (higher fee); Makati has none
        $this->assertTrue($opts[$this->pasig->id]['recommended']);
        $this->assertTrue($opts[$this->bgc->id]['ok']);
        $this->assertFalse($opts[$this->makati->id]['ok']);
        $this->assertStringContainsString('Gold Chiavari Chair', $opts[$this->makati->id]['short'][0]);
        $this->assertLessThan($opts[$this->bgc->id]['fee'], $opts[$this->pasig->id]['fee']);
        $this->assertLessThan($opts[$this->bgc->id]['km'], $opts[$this->pasig->id]['km']);

        // the checkout quote lists the branches and picks the recommended one
        $o = ['supplier_id' => $this->s->id, 'cart_line_ids' => CartLine::where('user_id', auth()->id())->pluck('id')->all(), 'fulfillment' => 'delivery', 'destinations' => [self::PASIG_ADDR], 'plan' => 'full', 'eventDate' => $this->day()];
        $q = $this->postJson(route('checkout.quote'), ['shared' => [], 'orders' => [$o]])->assertOk()->json('orders.0');
        $this->assertSame($this->pasig->id, $q['branchId']);
        $this->assertCount(3, $q['branches']);
        $this->assertStringContainsString('Branch A – Pasig', $q['html']);

        // choosing BGC instead: allowed (it has the chairs) and the delivery fee follows the branch
        $q2 = $this->postJson(route('checkout.quote'), ['shared' => [], 'orders' => [$o + ['branch_id' => $this->bgc->id]]])->json('orders.0');
        $this->assertSame($this->bgc->id, $q2['branchId']);
        $this->assertGreaterThan($q['summary']['q']['deliveryFee'], $q2['summary']['q']['deliveryFee']);
        $this->assertEqualsWithDelta($opts[$this->bgc->id]['fee'], $q2['summary']['q']['deliveryFee'], 0.01);

        // submit with the manual pick: the rental belongs to BGC, every line uses BGC's stock
        $this->checkout($this->s->id, $this->delivery(['branch_id' => $this->bgc->id]))->assertOk()->assertJsonPath('ok', true);
        $r = RentalRequest::latest('id')->firstOrFail();
        $this->assertSame($this->bgc->id, $r->branch_id);
        $this->assertSame([$this->bgc->id], array_values(array_unique(array_column($r->lines, 'sourceId'))));
        $this->assertEqualsWithDelta($opts[$this->bgc->id]['fee'], $r->pricing['deliveryFee'], 0.01);
        $this->get(route('rentals.show', $r))->assertOk()->assertSee('Rented from')->assertSee('Main Warehouse');
    }

    public function test_never_combines_stock_of_two_branches(): void
    {
        $this->asCustomer();
        // 120 chairs: 150 in total, but no single branch has 120
        $this->rent($this->chair->id, 120);
        $this->checkout($this->s->id, $this->delivery())->assertStatus(422)->assertJson(fn ($j) => $j->where('ok', false)->where('error', fn ($e) => str_contains($e, 'No single branch'))->etc());
        $this->assertSame(0, RentalRequest::where('customer_id', auth()->id())->where('supplier_id', $this->s->id)->where('status', 'pending')->where('start_date', $this->day())->count());

        // 60 chairs: BGC can, Pasig (50) cannot, so picking Pasig is refused with the reason
        CartLine::where('user_id', auth()->id())->delete();
        $this->rent($this->chair->id, 60);
        $this->checkout($this->s->id, $this->delivery(['branch_id' => $this->pasig->id]))->assertStatus(422)
            ->assertJson(fn ($j) => $j->where('error', fn ($e) => str_contains($e, 'Branch A – Pasig does not have everything') && str_contains($e, 'Main Warehouse can fulfil'))->etc());
        $this->checkout($this->s->id, $this->delivery())->assertOk();
        $this->assertSame($this->bgc->id, RentalRequest::latest('id')->first()->branch_id);
    }

    public function test_packages_need_every_item_at_the_same_branch(): void
    {
        $k = Package::where('supplier_id', $this->s->id)->where('name', 'Wedding Reception Package')->firstOrFail();
        $line = [['id' => 'l1', 'type' => 'package', 'ref_id' => $k->id, 'days' => [$this->day() => 1]]];
        // only BGC has the backdrop, cloths and uplights; Pasig has chairs but not the rest
        $this->assertSame(1, inv()->packageAvailableOn($k->id, $this->day(), null, null, null, null, $this->bgc->id));
        $this->assertSame(0, inv()->packageAvailableOn($k->id, $this->day(), null, null, null, null, $this->pasig->id));
        $this->assertTrue(Branches::check($line, $this->bgc)['ok']);
        $pasig = Branches::check($line, $this->pasig);
        $this->assertFalse($pasig['ok']);
        $this->assertNotEmpty($pasig['short']);

        // customer rents the package: BGC is the only eligible branch
        $this->asCustomer();
        $this->rent($k->id, 1, null, 'package');
        $this->checkout($this->s->id, $this->delivery(['branch_id' => $this->pasig->id]))->assertStatus(422);
        $this->checkout($this->s->id, $this->delivery())->assertOk();
        $this->assertSame($this->bgc->id, RentalRequest::latest('id')->first()->branch_id);
    }

    public function test_overlapping_rentals_cannot_overbook_a_branch_and_branches_do_not_interfere(): void
    {
        // Maria books 40 chairs from Pasig and pays (the booking holds the stock)
        $this->asCustomer();
        $this->rent($this->chair->id, 40);
        $this->checkout($this->s->id, $this->delivery(['branch_id' => $this->pasig->id]))->assertOk();
        $a = RentalRequest::latest('id')->firstOrFail();
        $a->update(['status' => 'accepted', 'accepted_at' => today_iso(), 'reserved_at' => now()]);
        inv()->flush();
        $this->assertSame(10, inv()->availableAtLocation($this->chair->id, $this->pasig->id, $this->day()));
        $this->assertSame(100, inv()->availableAtLocation($this->chair->id, $this->bgc->id, $this->day())); // BGC untouched

        // Jose wants 30 the same day: Pasig has only 10 left, so Pasig is refused and BGC is recommended
        $this->asCustomer('jose@demo.ph');
        $this->rent($this->chair->id, 30);
        $this->checkout($this->s->id, $this->delivery(['contactName' => 'Jose', 'branch_id' => $this->pasig->id]))->assertStatus(422);
        $this->checkout($this->s->id, $this->delivery(['contactName' => 'Jose']))->assertOk();
        $b = RentalRequest::latest('id')->firstOrFail();
        $this->assertSame($this->bgc->id, $b->branch_id);
        // the shop cannot move Jose's rental to Pasig either (not enough there)
        $liza = User::where('email', 'liza@demo.ph')->firstOrFail();
        $this->actingAs($liza)->withSession(['mode' => 'supplier'])
            ->post(route('supplier.requests.source', $b), ['line' => 'l1', 'location' => $this->pasig->id])->assertSessionHas('error');
        $this->assertSame($this->bgc->id, $b->fresh()->branch_id);

        // Maria cancels: Pasig's 40 chairs are free again, and Jose's rental can move there
        $a->update(['status' => 'cancelled']);
        inv()->flush();
        $this->assertSame(50, inv()->availableAtLocation($this->chair->id, $this->pasig->id, $this->day()));
        $this->post(route('supplier.requests.source', $b), ['line' => 'l1', 'location' => $this->pasig->id])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame($this->pasig->id, $b->branch_id);
        $this->assertSame([$this->pasig->id], array_values(array_unique(array_column($b->lines, 'sourceId'))));
    }

    public function test_supplier_manages_branches(): void
    {
        $liza = User::where('email', 'liza@demo.ph')->firstOrFail();
        $this->actingAs($liza)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.locations'))->assertOk()->assertSee('Branches')->assertSee('Branch A – Pasig')->assertSee('Wedding Reception Package');
        $this->get(route('supplier.locations.create'))->assertOk()->assertSee('Add a branch');

        // add a branch with a structured address and a pin
        $this->post(route('supplier.locations.store'), ['name' => 'Quezon City Branch', 'phone' => '0917 800 1717', 'contact' => 'Rico',
            'addr' => ['regionCode' => '130000000', 'provinceCode' => '137400000', 'cityCode' => '137404000', 'barangay' => 'Kamuning', 'street' => '5 Scout Torillo St.'],
            'lat' => 14.6290, 'lng' => 121.0360])->assertRedirect(route('supplier.locations'))->assertSessionHas('success');
        $qc = InventoryLocation::where('supplier_id', $this->s->id)->where('name', 'Quezon City Branch')->firstOrFail();
        $this->assertSame(['137404000', true, false], [$qc->city_code, $qc->active, $qc->is_main]);
        $this->assertEqualsWithDelta(14.629, $qc->lat, 0.0001);
        // a pin outside the country is refused; an invalid phone too
        $this->put(route('supplier.locations.update', $qc), ['name' => 'Quezon City Branch', 'address' => $qc->address, 'lat' => 40.7, 'lng' => -74.0])->assertSessionHas('error');
        $this->put(route('supplier.locations.update', $qc), ['name' => 'Quezon City Branch', 'address' => $qc->address, 'phone' => '12'])->assertSessionHas('error');
        $this->get(route('supplier.locations.edit', $qc))->assertOk()->assertSee('Quezon City Branch');

        // stock per branch on the product form: Quezon City gets 20 chairs of its own
        $this->put(route('supplier.products.update', $this->chair), ['name' => $this->chair->name, 'category_id' => $this->chair->category_id, 'description' => $this->chair->description, 'unit' => $this->chair->unit,
            'condition' => $this->chair->condition, 'status' => 'active', 'base_price' => 65, 'qty' => [$this->bgc->id => 100, $this->pasig->id => 50, $this->makati->id => 0, $qc->id => 20]])->assertSessionHasNoErrors();
        inv()->flush();
        $this->assertSame([20, 170], [inv()->stockAt($this->chair->id, $qc->id), $this->chair->fresh()->total_stock]);

        // deactivate: no longer offered to customers; the main branch cannot be deactivated
        $this->post(route('supplier.locations.active', $qc))->assertSessionHas('success');
        $this->assertFalse($qc->fresh()->active);
        $this->assertNotContains($qc->id, Branches::active($this->s->fresh())->pluck('id')->all());
        $this->post(route('supplier.locations.active', $qc))->assertSessionHas('success');
        $this->assertTrue($qc->fresh()->active);
        $this->post(route('supplier.locations.active', $this->bgc))->assertSessionHas('error');

        // a branch with open rentals cannot be deactivated until they move
        $r = RentalRequest::where('supplier_id', $this->s->id)->where('status', 'pending')->first() ?? RentalRequest::where('supplier_id', $this->s->id)->whereIn('status', ['accepted'])->firstOrFail();
        $r->update(['branch_id' => $this->pasig->id, 'lines' => Branches::onBranch($r->lines, $this->pasig->id)]);
        $this->post(route('supplier.locations.active', $this->pasig))->assertSessionHas('error', fn ($e) => str_contains($e, $r->code));

        // rentals per branch
        $this->get(route('supplier.requests', ['tab' => 'all', 'branch' => $this->pasig->id]))->assertOk()->assertSee($r->code);
        $other = RentalRequest::where('supplier_id', $this->s->id)->where('id', '!=', $r->id)->where('branch_id', $this->bgc->id)->first();
        if ($other) {
            $this->get(route('supplier.requests', ['tab' => 'all', 'branch' => $this->pasig->id]))->assertDontSee('>'.$other->code.'<', false);
        }

        // another shop's branch is off limits
        $juanBranch = InventoryLocation::where('supplier_id', '!=', $this->s->id)->firstOrFail();
        $this->get(route('supplier.locations.edit', $juanBranch))->assertNotFound();
        $this->post(route('supplier.locations.active', $juanBranch))->assertNotFound();
    }

    public function test_single_branch_supplier_and_registration_with_branches(): void
    {
        // Dream Events has one branch: rentals just use it (pickup there)
        $dream = Supplier::where('name', 'Dream Events Supplies')->firstOrFail();
        $table = Product::where('supplier_id', $dream->id)->where('name', 'Folding Banquet Table')->firstOrFail();
        $this->asCustomer();
        $this->rent($table->id, 5);
        $this->checkout($dream->id, ['contactName' => 'Maria', 'contactPhone' => '0917 555 0101', 'eventDate' => $this->day(), 'fulfillment' => 'pickup'])->assertOk();
        $r = RentalRequest::latest('id')->firstOrFail();
        $this->assertSame(Branches::main($dream)->id, $r->branch_id);

        // a shop without any location gets a main branch from its own address, holding its stock
        $bare = Supplier::where('status', 'pending')->firstOrFail();
        $bare->locations()->delete();
        $main = Branches::main($bare->fresh());
        $this->assertTrue($main->is_main);
        $this->assertSame($bare->address, $main->address);

        // registration: no company type, optional extra branches that become branches once approved
        $this->get('/start-selling')->assertOk()->assertDontSee('name="app[company][companyType]"', false)->assertSee('Do you have other branches?');
        $this->assertSame('business_registration', \App\Domain\Applications::requirements('organization')[0]['key']);
        $bare->update(['application' => array_merge((array) $bare->application, ['branches' => [['name' => 'North Warehouse', 'address' => '10 EDSA, Caloocan', 'phone' => '0917 111 2222']]])]);
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->post(route('admin.applications.review', $bare), ['decision' => 'approve'])->assertSessionHas('success');
        $this->assertSame(['North Warehouse'], $bare->fresh()->locations()->where('is_main', false)->pluck('name')->all());
    }

    /** Extra branches in the application are filled in like the main branch: name, number, structured address, pin. */
    public function test_application_branches_use_the_main_branch_form(): void
    {
        config(['partyrent.event_companies' => false]); // a rental company (the default scope)
        \Illuminate\Support\Facades\Storage::fake('local');
        $pdf = fn ($n) => \Illuminate\Http\UploadedFile::fake()->create($n, 40, 'application/pdf');
        $this->asCustomer('jose@demo.ph');
        $this->get('/start-selling')->assertOk()->assertSee('data-branch-tpl', false)->assertSee('app[branches][__i__][addr][regionCode]', false);
        $branch = ['name' => 'Pasig Branch', 'contact' => 'Ana', 'phone' => '0917 222 3333',
            'addr' => ['regionCode' => '130000000', 'provinceCode' => '137400000', 'cityCode' => '137403000', 'barangay' => 'Kapitolyo', 'street' => '5 United St.'], 'lat' => 14.5700, 'lng' => 121.0600];
        $form = [
            'app' => ['type' => 'organization',
                'company' => ['legalName' => 'Garcia Rentals Corp.', 'address' => '7 Sampaguita St., Marikina Heights, Marikina', 'email' => 'ops@garcia.ph', 'phone' => '0923 555 0707', 'natureOfBusiness' => 'Party and event equipment rental'],
                'representative' => ['name' => 'Jose Garcia', 'position' => 'Manager', 'idType' => 'Passport', 'email' => 'jose@demo.ph', 'phone' => '0923 555 0707'],
                'business' => ['productTypes' => 'Tents, chairs'], 'branches' => [$branch],
                'payout' => ['method' => 'bank', 'provider' => 'BPI', 'accountName' => 'Garcia Rentals Corp.', 'accountNumber' => '1234567890', 'financeContact' => 'Ana Garcia']],
            'docs' => ['business_registration' => $pdf('reg.pdf'), 'bir_cor' => $pdf('bir.pdf'), 'mayors_permit' => $pdf('permit.pdf'), 'rep_id' => $pdf('id.pdf'), 'proof_of_authority' => $pdf('auth.pdf')],
            'name' => 'Garcia Rentals', 'description' => 'Tents, chairs and backdrops for Marikina parties.', 'logo' => '🎪',
            'phone' => '0923 555 0707', 'email' => 'ops@garcia.ph', 'hours' => 'Daily 8–6',
            'shop_addr' => ['regionCode' => '130000000', 'provinceCode' => '137400000', 'cityCode' => '137402000', 'barangay' => 'Marikina Heights', 'street' => '7 Sampaguita St.'],
            'location_type' => 'shop', 'lat' => 14.6520, 'lng' => 121.1100, 'fulfillment' => 'both', 'methods' => ['cash', 'bank'],
            'cancelDays' => 5, 'cancellation' => 'Free up to 5 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.',
        ];
        // a branch needs the same details as the main one: no city / bad number is refused on its step
        $bad = $form;
        $bad['app']['branches'][0]['addr']['cityCode'] = '';
        $bad['app']['branches'][0]['phone'] = '123';
        $this->post('/start-selling/check', array_merge($bad, ['step' => 3]))->assertStatus(422)
            ->assertJsonValidationErrors(['app.branches.0.addr.cityCode', 'app.branches.0.phone']);
        $this->post('/start-selling', $form)->assertSessionHasNoErrors()->assertSessionHas('success');

        $s = Supplier::where('name', 'Garcia Rentals')->firstOrFail();
        $this->assertSame('137403000', $s->application['branches'][0]['cityCode']);
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->post(route('admin.applications.review', $s), ['decision' => 'approve'])->assertSessionHas('success');
        $branches = $s->fresh()->branches()->get();
        $this->assertCount(2, $branches);
        $this->assertTrue($branches[0]->is_main);
        $pasig = $branches[1];
        $this->assertSame(['Pasig Branch', '137403000', '0917 222 3333', 'Ana'], [$pasig->name, $pasig->city_code, $pasig->phone, $pasig->contact]);
        $this->assertEqualsWithDelta(14.57, $pasig->lat, 0.0001);
        $this->assertStringContainsString('Kapitolyo', $pasig->address);
        $this->assertSame('5 United St.', $pasig->address_parts['street']);
    }
}
