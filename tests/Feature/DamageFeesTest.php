<?php

namespace Tests\Feature;

use App\Domain\Damage;
use App\Domain\Rentals;
use App\Models\CartLine;
use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item damage fees: set per item, shown before booking (items and packages itemized), kept with each rental at
 * booking, and charged only for damage the shop records at return.
 * Demo: Happy Moments' Birthday Party Package = 50 chairs, 5 round tables, 1 balloon garland, 5 tablecloths.
 */
class DamageFeesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private Product $chair;

    private Product $table;

    private Product $garland;

    private Product $cloth;

    private Package $pkg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Supplier::where('name', 'Happy Moments Party Rentals')->firstOrFail();
        $find = fn ($n) => Product::where('supplier_id', $this->s->id)->where('name', $n)->firstOrFail();
        $this->chair = $find('White Monoblock Chair');
        $this->table = $find('Round Table (8-Seater)');
        $this->garland = $find('Pink Balloon Garland Set');
        $this->cloth = $find('White Round Tablecloth');
        // fees: chair ₱500 (minor ₱50), table ₱3,500 (minor ₱200), tablecloth ₱600, garland: no damage fee
        $this->chair->update(['replacement_value' => 500, 'minor_fee' => 50]);
        $this->table->update(['replacement_value' => 3500, 'minor_fee' => 200]);
        $this->cloth->update(['replacement_value' => 600, 'minor_fee' => null]);
        $this->garland->update(['replacement_value' => null, 'minor_fee' => null]);
        $this->pkg = Package::where('supplier_id', $this->s->id)->where('name', 'Birthday Party Package')->firstOrFail();
        inv()->flush();
    }

    private function day(): string
    {
        return add_days(today_iso(), 42);
    }

    /** Maria books lines (cart rows) and checks out; returns the pending rental. */
    private function book(array $rows): RentalRequest
    {
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        CartLine::where('user_id', $maria->id)->delete();
        $this->actingAs($maria)->withSession(['mode' => 'customer']);
        foreach ($rows as [$type, $id, $qty]) {
            $this->post('/cart', ['type' => $type, 'ref_id' => $id, 'days' => json_encode([$this->day() => $qty])])->assertSessionHas('success');
        }
        $this->checkout($this->s->id, ['contactName' => 'Maria', 'contactPhone' => '0917 555 0101', 'fulfillment' => 'pickup'])->assertOk();

        return RentalRequest::where('customer_id', $maria->id)->latest('id')->firstOrFail();
    }

    /** The shop records the return inspection ($rows: productId => outcome input). */
    private function inspect(RentalRequest $r, array $rows): array
    {
        $r->update(['status' => 'return_pending', 'inspection' => null, 'reserved_at' => now()]);
        $items = [];
        foreach (Rentals::peakUnits($r) as $pid => $q) {
            $items[$pid] = array_merge(['returned' => $q], $rows[$pid] ?? []);
        }
        $this->actingAs($this->s->owner);

        return Rentals::recordInspection($r->fresh(), ['returnDate' => today_iso(), 'items' => $items, 'confirmed' => true]);
    }

    public function test_items_show_their_damage_fee_or_that_none_is_set(): void
    {
        $this->get(route('products.show', $this->chair))->assertOk()
            ->assertSee('Damage fee')->assertSee('Up to '.money(500))->assertSee('minor damage '.money(50))->assertSee('Not part of the rental price');
        $this->get(route('products.show', $this->garland))->assertOk()->assertSee('No damage fee set');
        // the supplier sets them on the item form; only for their own items, never negative
        $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier'])->get(route('supplier.products.edit', $this->chair))->assertOk()->assertSee('Damage fees')->assertSee('Lost or beyond repair');
        $other = Product::where('supplier_id', '!=', $this->s->id)->where('listing_type', 'rent')->firstOrFail();
        $this->get(route('supplier.products.edit', $other))->assertNotFound();
        $this->put(route('supplier.products.update', $this->chair), ['name' => $this->chair->name, 'category_id' => $this->chair->category_id, 'description' => $this->chair->description, 'unit' => $this->chair->unit,
            'condition' => $this->chair->condition, 'status' => 'active', 'base_price' => 15, 'minor_fee' => -5])->assertSessionHasErrors('minor_fee');
    }

    public function test_package_damage_fees_are_itemized(): void
    {
        $rows = Damage::termsForLines([['id' => 'x', 'type' => 'package', 'ref_id' => $this->pkg->id, 'days' => [$this->day() => 1]]]);
        $this->assertSame([50, 5, 1, 5], [$rows[$this->chair->id]['qty'], $rows[$this->table->id]['qty'], $rows[$this->garland->id]['qty'], $rows[$this->cloth->id]['qty']]);
        $this->assertNull($rows[$this->garland->id]['max']); // no fee: not given one from the package
        $this->get(route('packages.show', $this->pkg))->assertOk()
            ->assertSee('If something gets damaged')->assertSee('up to '.money(50 * 500 + 5 * 3500 + 5 * 600).' per package')
            ->assertSee('White Monoblock Chair')->assertSee('No fee set');
        // two packages: each item's quantity doubles
        $two = Damage::termsForLines([['id' => 'x', 'type' => 'package', 'ref_id' => $this->pkg->id, 'days' => [$this->day() => 2]]]);
        $this->assertSame(100, $two[$this->chair->id]['qty']);
    }

    public function test_package_and_extra_items_are_counted_once_and_kept_at_booking(): void
    {
        // a package (50 chairs) and 8 more chairs on their own: one chair row of 58, not two
        $r = $this->book([['package', $this->pkg->id, 1], ['item', $this->chair->id, 8]]);
        $t = $r->pricing['damageTerms'];
        $this->assertSame(58, $t[$this->chair->id]['qty']);
        $this->assertEquals(500, $t[$this->chair->id]['max']);
        $this->assertCount(4, $t);

        // the shop raises the chair's fee after the booking: this rental keeps ₱500, and the change is audited
        $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier'])->put(route('supplier.products.update', $this->chair), [
            'name' => $this->chair->name, 'category_id' => $this->chair->category_id, 'description' => $this->chair->description, 'unit' => $this->chair->unit,
            'condition' => $this->chair->condition, 'status' => 'active', 'base_price' => 15, 'replacement_value' => 900, 'minor_fee' => 90,
            'qty' => inv()->stockByLocation($this->chair->id)])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertEquals(900, $this->chair->fresh()->replacement_value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated damage fee']);
        $this->assertEquals(500, $r->fresh()->pricing['damageTerms'][$this->chair->id]['max']);

        // one chair from the package comes back broken: charged the ₱500 agreed at booking, nothing else
        $res = $this->inspect($r, [$this->chair->id => ['beyond' => 1, 'description' => 'Leg snapped']]);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $insp = $r->fresh()->inspection;
        $this->assertEquals(500, $insp['compensation']);
        $chairRow = collect($insp['items'])->firstWhere('productId', $this->chair->id);
        $this->assertTrue($chairRow['termsKept']);
        $this->assertSame(0.0, (float) collect($insp['items'])->firstWhere('productId', $this->table->id)['total']);
    }

    public function test_several_damaged_items_some_units_and_a_missing_one(): void
    {
        $r = $this->book([['package', $this->pkg->id, 1]]);
        // 2 chairs with minor damage (2 × ₱50), 1 table missing (₱3,500), 1 tablecloth repairable (repair ₱150), garland broken (no fee: ₱0)
        $res = $this->inspect($r, [
            $this->chair->id => ['minor' => 2, 'description' => 'Two scuffed'],
            $this->table->id => ['returned' => 4, 'description' => 'One table not returned'],
            $this->cloth->id => ['repairable' => 1, 'repairCost' => 150, 'description' => 'Wine stain needs dry cleaning'],
            $this->garland->id => ['beyond' => 1, 'description' => 'Frame bent'],
        ]);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $insp = $r->fresh()->inspection;
        $by = collect($insp['items'])->keyBy('productId');
        $this->assertEquals(100, $by[$this->chair->id]['total']);
        $this->assertEquals(3500, $by[$this->table->id]['total']);
        $this->assertSame(1, $by[$this->table->id]['lost']);
        $this->assertEquals(150, $by[$this->cloth->id]['total']);
        $this->assertEquals(0, $by[$this->garland->id]['total']); // no damage fee set: nothing to charge
        $this->assertEquals(3750, $insp['compensation']);
        $this->assertSame('pending', $insp['review']['status']); // the customer can accept or dispute each charge
        // the customer sees the assessed charges on their rental; they cannot change them
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->get(route('rentals.show', $r))->assertOk()->assertSee(money(3750));
        $this->post(route('supplier.requests.inspection', $r), ['returnDate' => today_iso(), 'items' => [], 'confirmed' => 1])->assertSessionMissing('success');
        $this->assertEquals(3750, $r->fresh()->inspection['compensation']);
    }

    public function test_all_items_back_fine_means_no_charge(): void
    {
        $r = $this->book([['package', $this->pkg->id, 1], ['item', $this->table->id, 2]]);
        $res = $this->inspect($r, []);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $insp = $r->fresh()->inspection;
        $this->assertEquals(0, $insp['compensation']); // finishing a rental never charges a damage fee by itself
        $this->assertArrayNotHasKey('review', $insp);
    }
}
