<?php

namespace Tests\Feature;

use App\Models\LocationStock;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supplier Inventory Management page (reference SP.inventory): stock per location, condition counts, holds. */
class InventoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_update_stock_per_location_and_conditions(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $sup = $juan->supplier;
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.inventory'))->assertOk()->assertSee('Inventory Management')->assertSee('Update stock')->assertSee('Maintenance & set-aside schedule', false);

        $chair = Product::where('supplier_id', $sup->id)->where('name', 'White Monoblock Chair')->firstOrFail();
        // C9: the maintenance / set-aside form shows the total stock and an "All N units" option
        $this->get(route('supplier.inventory'))->assertSee('All '.$chair->total_stock.' units')->assertSee('total stock '.$chair->total_stock);
        $locs = $sup->locations()->get();
        $this->assertGreaterThanOrEqual(1, $locs->count());
        $qty = $locs->mapWithKeys(fn ($l) => [$l->id => inv()->stockAt($chair->id, $l->id)])->all();

        // below what accepted bookings need at a location → refused
        $first = array_key_first($qty);
        $this->post(route('supplier.inventory.stock', $chair), ['qty' => [$first => 0] + $qty])->assertSessionHas('error');

        // add 10 units at the first location → total follows the sum
        $qty[$first] += 10;
        $this->post(route('supplier.inventory.stock', $chair), ['qty' => $qty])->assertSessionHas('success');
        $this->assertSame(array_sum($qty), $chair->fresh()->total_stock);
        $this->assertSame($qty[$first], (int) LocationStock::where('product_id', $chair->id)->where('inventory_location_id', $first)->value('qty'));

        // damaged + missing together
        $this->post(route('supplier.inventory.condition', $chair), ['damaged' => 0, 'missing' => 3])->assertSessionHas('success');
        $chair->refresh();
        $this->assertSame([0, 3], [(int) $chair->damaged, (int) $chair->missing]);
    }
}
