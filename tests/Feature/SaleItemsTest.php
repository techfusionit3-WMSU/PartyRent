<?php

namespace Tests\Feature;

use App\Models\CartLine;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Consumables sold (not rented): listing form, restock, lead time, and stock deducted on release. */
class SaleItemsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    private function saleForm(array $over = []): array
    {
        return array_merge([
            'listing_type' => 'sale', 'name' => 'Cupcake Tray (24 pcs)', 'category_id' => 'catering', 'description' => 'Two dozen vanilla cupcakes with pastel frosting.',
            'total_stock' => 10, 'unit' => 'tray', 'status' => 'active', 'base_price' => 900,
            'perishable' => '1', 'shelf_life_days' => 2, 'lead_days' => 3, 'low_stock_at' => 4, 'delivery' => '1',
        ], $over);
    }

    private function createSaleItem(array $over = []): Product
    {
        $this->as('juan@demo.ph', 'supplier')->post('/supplier/products', $this->saleForm($over))->assertSessionHas('success');

        return Product::where('name', $over['name'] ?? 'Cupcake Tray (24 pcs)')->firstOrFail();
    }

    public function test_supplier_lists_an_item_for_sale(): void
    {
        $this->as('juan@demo.ph', 'supplier')->get('/supplier/products/new?type=sale')->assertOk()->assertSee('Add an item for sale')->assertSee('Stock on hand');
        $p = $this->createSaleItem();
        $this->assertTrue($p->isSale());
        $this->assertSame('tray', $p->unit);
        $this->assertTrue($p->perishable);
        $this->assertSame(2, $p->shelf_life_days);
        $this->assertSame(3, $p->lead_days);
        $this->assertSame('per tray', $p->priceUnitLabel());
        $this->get('/products/'.$p->id)->assertOk()->assertSee('Cupcake Tray');

        // a rental unit sent with a sale item falls back to a sale unit, and rental items ignore the sale fields
        $q = $this->createSaleItem(['name' => 'Balloon Bundle', 'unit' => 'set / day', 'perishable' => null]);
        $this->assertSame('pc', $q->unit);
        $this->assertNull($q->shelf_life_days);
        $this->post('/supplier/products', $this->saleForm(['listing_type' => 'rent', 'name' => 'Rental Cake Stand', 'unit' => 'piece / day']))->assertSessionHas('success');
        $r = Product::where('name', 'Rental Cake Stand')->firstOrFail();
        $this->assertFalse($r->isSale());
        $this->assertSame(0, $r->lead_days);
        $this->assertNull($r->low_stock_at);

        $this->get('/supplier/products')->assertOk()->assertSee('For sale');
        $this->get('/supplier/inventory')->assertOk()->assertSee('Restock');
    }

    public function test_restock_logs_a_movement_and_only_works_for_sale_items(): void
    {
        $p = $this->createSaleItem();
        $this->post("/supplier/inventory/{$p->id}/restock", ['qty' => 5, 'note' => 'Morning bake'])->assertSessionHas('success');
        $this->assertSame(15, $p->fresh()->stockOnHand());
        $m = StockMovement::where('product_id', $p->id)->where('type', 'restock')->firstOrFail();
        $this->assertSame(5, $m->qty);
        $this->assertSame(15, $m->balance);

        $rental = Product::where('supplier_id', $p->supplier_id)->where('listing_type', 'rent')->first();
        $this->post("/supplier/inventory/{$rental->id}/restock", ['qty' => 5])->assertSessionHas('error');
        $this->post("/supplier/inventory/{$p->id}/restock", ['qty' => 0])->assertSessionHasErrors('qty');

        // someone else's item is not found
        $other = Product::where('supplier_id', '!=', $p->supplier_id)->first();
        $this->post("/supplier/inventory/{$other->id}/restock", ['qty' => 1])->assertNotFound();
    }

    public function test_lead_time_and_stock_are_enforced_and_release_deducts_stock(): void
    {
        $p = $this->createSaleItem();
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        CartLine::where('user_id', $maria->id)->delete();
        $shop = Supplier::findOrFail($p->supplier_id);

        // inside the 3-day lead time
        $this->as('maria@demo.ph', 'customer')->post('/cart', ['type' => 'item', 'ref_id' => $p->id, 'days' => json_encode([add_days(today_iso(), 1) => 2])])->assertSessionHas('error');
        // more than the stock on hand
        $d = add_days(today_iso(), 10);
        $this->post('/cart', ['type' => 'item', 'ref_id' => $p->id, 'days' => json_encode([$d => 11])])->assertSessionHas('error');

        $this->post('/cart', ['type' => 'item', 'ref_id' => $p->id, 'days' => json_encode([$d => 7])])->assertSessionHas('success');
        $this->checkout($shop->id, [
            'eventName' => 'Cupcake Party', 'eventType' => 'Birthday', 'eventDate' => $d, 'fulfillment' => 'pickup',
            'contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101', 'arrangement' => 'full', 'method' => 'cod',
        ])->assertOk()->assertJsonPath('ok', true);
        $r = RentalRequest::where('event_name', 'Cupcake Party')->firstOrFail();
        $this->assertEquals(6300.0, $r->pricing['salesSubtotal']); // 7 × 900, sold once (not per day)

        // v4: the supplier accepts first; payments follow
        $this->as('juan@demo.ph', 'supplier')->post("/supplier/requests/{$r->code}/accept")->assertSessionHas('success');
        inv()->flush();
        $this->assertSame(7, inv()->committedSale($p->id));
        // stock on hand cannot drop below what accepted orders need
        $this->put("/supplier/products/{$p->id}", $this->saleForm(['total_stock' => 5]))->assertSessionHas('error');

        foreach (['in_progress', 'ready_pickup'] as $to) {
            $this->post("/supplier/requests/{$r->code}/advance", ['to' => $to])->assertSessionHas('success');
        }
        $this->post("/supplier/requests/{$r->code}/advance", ['to' => 'ongoing', 'receivedBy' => 'Maria', 'releaseDate' => today_iso()])->assertSessionHas('success');
        $p->refresh();
        $this->assertSame(3, $p->stockOnHand());
        $this->assertTrue($p->isLowStock());
        $sold = StockMovement::where('product_id', $p->id)->where('type', 'sold')->firstOrFail();
        $this->assertSame(-7, $sold->qty);
        $this->assertSame($r->id, $sold->rental_request_id);
    }
}
