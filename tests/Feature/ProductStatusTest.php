<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supplier Products page actions (reference SP.products): activate / deactivate, archive rules, restore. */
class ProductStatusTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_deactivate_archive_and_restore(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $chair = Product::where('supplier_id', $juan->supplier->id)->where('name', 'White Monoblock Chair')->firstOrFail();

        $this->post(route('supplier.products.status', $chair), ['status' => 'inactive'])->assertSessionHas('success');
        $this->assertSame('inactive', $chair->fresh()->status);
        $this->get(route('supplier.products', ['f' => 'inactive']))->assertOk()->assertSee('White Monoblock Chair');
        $this->post(route('supplier.products.status', $chair), ['status' => 'active'])->assertSessionHas('success');

        // accepted reservations (PR-0001) block archiving
        $this->post(route('supplier.products.status', $chair), ['status' => 'archived'])->assertSessionHas('error', fn ($e) => str_contains($e, 'accepted reservations'));
        $this->assertSame('active', $chair->fresh()->status);

        // an item without reservations can be archived; packages that use it are deactivated
        $tent = Product::where('supplier_id', $juan->supplier->id)->where('name', 'like', '10×10%')->firstOrFail();
        if (inv()->reservedDates($tent->id)) {
            $this->markTestSkipped('seed has reservations for the tent');
        }
        $this->post(route('supplier.products.status', $tent), ['status' => 'archived'])->assertSessionHas('success');
        $this->assertSame('archived', $tent->fresh()->status);
        $this->get(route('supplier.products'))->assertDontSee('10×10 ft Canopy Tent');
        $this->get(route('supplier.products', ['f' => 'archived']))->assertSee('10×10 ft Canopy Tent')->assertSee('Restore');
        $this->assertFalse((bool) Package::where('supplier_id', $juan->supplier->id)->where('name', 'Outdoor Party Package')->value('active'));

        $this->post(route('supplier.products.status', $tent), ['status' => 'restore'])->assertSessionHas('success');
        $this->assertSame('inactive', $tent->fresh()->status);

        // hidden by an admin: locked
        $chair->update(['status' => 'hidden', 'hidden_reason' => 'Misleading photos']);
        $this->post(route('supplier.products.status', $chair), ['status' => 'active'])->assertSessionHas('error');
        $this->get(route('supplier.products', ['f' => 'hidden']))->assertSee('Misleading photos')->assertSee('Cannot be republished');
    }
}
