<?php

namespace Tests\Feature;

use App\Domain\Rentals;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Replacement value per item and the v4 damage policy (value × compensation % × affected quantity). */
class ReplacementValueTest extends TestCase
{
    use RefreshDatabase;

    /** These cover the business-shop percentage damage policy (individual shops use outcomes: DamageOutcomesTest). */
    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Supplier::query()->update(['account_type' => 'business']);
    }

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function returnPending(): array
    {
        $r = RentalRequest::whereIn('status', ['ongoing', 'accepted', 'completed'])->get()
            ->first(fn ($r) => Rentals::peakUnits($r) !== []);
        $this->assertNotNull($r, 'demo data has a rental with items');
        $r->status = 'return_pending';
        $r->inspection = null;
        $r->save();
        $peak = Rentals::peakUnits($r);
        $pid = array_key_first($peak);

        return [$r, $pid, $peak[$pid]];
    }

    private function items(array $peak, int $pid, array $row): array
    {
        $out = [];
        foreach ($peak as $id => $qty) {
            $out[$id] = ['returned' => $qty, 'repairable' => 0, 'nonRepairable' => 0, 'description' => ''];
        }
        $out[$pid] = array_merge($out[$pid], $row);

        return ['returnDate' => today_iso(), 'items' => $out, 'confirmed' => true];
    }

    public function test_supplier_saves_value_and_customer_sees_it(): void
    {
        $p = Product::where('listing_type', 'rent')->where('status', 'active')->firstOrFail();
        $owner = Supplier::find($p->supplier_id)->owner;
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get(route('supplier.products.edit', $p))
            ->assertOk()->assertSee('Replacement value (₱ per unit)', false);
        $p->update(['replacement_value' => 850]);
        $this->get(route('products.show', $p))->assertOk()->assertSee('Replacement value')->assertSee('850');
    }

    public function test_compensation_follows_the_damage_policy(): void
    {
        [$r, $pid, $q] = $this->returnPending();
        $this->assertGreaterThanOrEqual(2, $q, 'need at least 2 units for this scenario');
        Product::whereKey($pid)->update(['replacement_value' => 1000, 'condition' => 'used']);
        Supplier::whereKey($r->supplier_id)->update(['damage_policy' => null]); // defaults: 50 / 70 / lost used 70
        inv()->flush();
        $peak = Rentals::peakUnits($r);
        $before = Product::find($pid);

        // a damaged or lost unit needs a description
        $bad = Rentals::recordInspection($r->fresh(), $this->items($peak, $pid, ['returned' => $q - 1]));
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('describe the damage', $bad['error']);
        // nothing is recorded without the review step
        $noConfirm = $this->items($peak, $pid, ['returned' => $q - 1, 'description' => 'One missing']);
        unset($noConfirm['confirmed']);
        $this->assertFalse(Rentals::recordInspection($r->fresh(), $noConfirm)['ok']);

        // 1 lost (70%), 1 repairable (50%) → 700 + 500
        $ok = Rentals::recordInspection($r->fresh(), $this->items($peak, $pid, ['returned' => $q - 1, 'repairable' => 1, 'description' => 'One cracked, one missing']));
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $x = $r->fresh()->inspection;
        $this->assertSame(1200.0, (float) $x['compensation']);
        $this->assertSame('pending', $x['settlement']);
        $p = Product::find($pid);
        $this->assertSame($before->damaged + 1, $p->damaged);
        $this->assertSame($before->missing + 1, $p->missing);

        // compensation can only be recorded once
        $again = $r->fresh();
        $again->status = 'return_pending';
        $this->assertFalse(Rentals::recordInspection($again, $this->items($peak, $pid, []))['ok']);
    }

    public function test_category_rule_overrides_the_shop_default(): void
    {
        [$r, $pid, $q] = $this->returnPending();
        $p = Product::find($pid);
        $p->update(['replacement_value' => 1000, 'condition' => 'brand_new']);
        Supplier::whereKey($r->supplier_id)->update(['damage_policy' => ['nonRepairable' => ['enabled' => true, 'pct' => 70], 'categories' => [$p->category_id => ['nonRepairable' => ['enabled' => true, 'pct' => 80]]]]]);
        inv()->flush();
        $ok = Rentals::recordInspection($r->fresh(), $this->items(Rentals::peakUnits($r), $pid, ['nonRepairable' => 1, 'description' => 'Broken']));
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $it = collect($r->fresh()->inspection['items'])->firstWhere('productId', $pid);
        $this->assertEquals(80, $it['pcts']['nonRepairable']);
        $this->assertSame('category', $it['rateSource']);
        $this->assertSame(800.0, (float) $r->fresh()->inspection['compensation']);
        $this->assertSame($p->written_off + 1, Product::find($pid)->written_off);
    }
}
