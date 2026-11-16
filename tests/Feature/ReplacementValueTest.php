<?php

namespace Tests\Feature;

use App\Domain\Rentals;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Replacement value per item: saved by the supplier, shown to customers, caps damage charges. */
class ReplacementValueTest extends TestCase
{
    use RefreshDatabase;

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

    private function items(array $peak, int $pid, int $q, array $row): array
    {
        $out = [];
        foreach ($peak as $id => $qty) {
            $out[$id] = ['returned' => $qty, 'good' => $qty, 'minor' => 0, 'major' => 0, 'total' => 0, 'missing' => 0, 'repairFee' => 0, 'replaceFee' => 0];
        }
        $out[$pid] = array_merge($out[$pid], $row);

        return ['returnDate' => today_iso(), 'items' => $out];
    }

    public function test_supplier_saves_value_and_customer_sees_it(): void
    {
        $p = Product::where('listing_type', 'rent')->where('status', 'active')->firstOrFail();
        $owner = Supplier::find($p->supplier_id)->owner;
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get(route('supplier.products.edit', $p))
            ->assertOk()->assertSee('Replacement value per piece');
        $p->update(['replacement_value' => 850]);
        $this->get(route('products.show', $p))->assertOk()->assertSee('Replacement value')->assertSee('850');
    }

    public function test_charges_cannot_exceed_replacement_value(): void
    {
        [$r, $pid, $q] = $this->returnPending();
        Product::whereKey($pid)->update(['replacement_value' => 1000]);
        inv()->flush();
        $peak = Rentals::peakUnits($r);

        // 1 missing → at most ₱1,000
        $bad = Rentals::recordInspection($r, $this->items($peak, $pid, $q, ['returned' => $q - 1, 'good' => $q - 1, 'missing' => 1, 'replaceFee' => 1500]));
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('replacement value', $bad['error']);

        $ok = Rentals::recordInspection($r->fresh(), $this->items($peak, $pid, $q, ['returned' => $q - 1, 'good' => $q - 1, 'missing' => 1, 'replaceFee' => 1000]));
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame(1000.0, (float) $r->fresh()->inspection['compensation']);
    }

    public function test_items_without_a_value_are_not_capped(): void
    {
        [$r, $pid, $q] = $this->returnPending();
        Product::whereKey($pid)->update(['replacement_value' => null]);
        inv()->flush();
        $ok = Rentals::recordInspection($r, $this->items(Rentals::peakUnits($r), $pid, $q, ['returned' => $q - 1, 'good' => $q - 1, 'missing' => 1, 'replaceFee' => 99999]));
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
    }
}
