<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Reference SP.locations: add / edit / remove locations, transfers between them, movement records. */
class InventoryLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_locations_and_transfers(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $s = $juan->supplier;
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.locations'))->assertOk()->assertSee('Stock by location');

        // add + duplicate name refused
        $this->post(route('supplier.locations.store'), ['name' => 'Branch B', 'address' => '12 Aurora Blvd., Cubao'])->assertSessionHas('success');
        $this->post(route('supplier.locations.store'), ['name' => 'branch b', 'address' => '12 Aurora Blvd., Cubao'])->assertSessionHas('error');
        $b = InventoryLocation::where('supplier_id', $s->id)->where('name', 'Branch B')->firstOrFail();

        // transfer 5 tables from the first location to Branch B; total stays the same
        $table = Product::where('supplier_id', $s->id)->where('name', 'like', 'Rectangular%')->firstOrFail();
        $from = $s->locations()->first();
        $before = inv()->stockAt($table->id, $from->id);
        $this->post(route('supplier.locations.transfer', $table), ['from' => $from->id, 'to' => $b->id, 'qty' => $before + 1])->assertSessionHas('error');
        $this->post(route('supplier.locations.transfer', $table), ['from' => $from->id, 'to' => $b->id, 'qty' => 5, 'note' => 'For Cubao'])->assertSessionHas('success');
        inv()->flush();
        $this->assertSame([$before - 5, 5], [inv()->stockAt($table->id, $from->id), inv()->stockAt($table->id, $b->id)]);
        $this->assertSame(15, $table->fresh()->total_stock);
        $m = StockMovement::where('product_id', $table->id)->where('type', 'transfer')->firstOrFail();
        $this->assertSame([$from->id, $b->id], [$m->inventory_location_id, $m->to_location_id]);
        $this->get(route('supplier.locations'))->assertSee('For Cubao')->assertSee('Transfer');

        // a location that holds stock cannot be removed
        $this->delete(route('supplier.locations.delete', $b))->assertSessionHas('error', fn ($e) => str_contains($e, 'still holds stock'));
        $this->post(route('supplier.locations.transfer', $table), ['from' => $b->id, 'to' => $from->id, 'qty' => 5])->assertSessionHas('success');
        $this->delete(route('supplier.locations.delete', $b))->assertSessionHas('success');
        $this->assertNull(InventoryLocation::find($b->id));
    }
}
