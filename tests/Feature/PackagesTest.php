<?php

namespace Tests\Feature;

use App\Models\CartLine;
use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supplier-built packages: builder, list actions, customer choices, pricing and booking snapshots. */
class PackagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    private function cupcakes(): Product
    {
        $p = Product::findOrFail(8)->replicate(['rating', 'review_count', 'rent_count']);
        $p->fill(['name' => 'Cupcake Tray', 'listing_type' => 'sale', 'unit' => 'tray', 'total_stock' => 10, 'tiers' => [['min' => 1, 'max' => null, 'price' => 900]], 'category_id' => 'catering'])->save();

        return $p;
    }

    /** Fixed ₱2,000: 2 round tables + 1 cupcake tray + setup crew; chair-or-table choice (+₱50 for the table); optional host. */
    private function def(int $cupcakeId, array $over = []): array
    {
        return array_merge([
            'pricing' => ['mode' => 'fixed', 'price' => 2000, 'discountPct' => 0],
            'components' => [
                ['kind' => 'rent', 'productId' => 2, 'qty' => 2],
                ['kind' => 'sale', 'productId' => $cupcakeId, 'qty' => 1],
                ['kind' => 'service', 'name' => 'Setup crew', 'price' => 500, 'qty' => 1, 'unit' => 'crew'],
                ['kind' => 'choice', 'label' => 'Seating upgrade', 'options' => [['productId' => 1, 'qty' => 10, 'priceDelta' => 0], ['productId' => 3, 'qty' => 1, 'priceDelta' => 50]]],
                ['kind' => 'addon', 'productId' => null, 'name' => 'Party host', 'price' => 800, 'qty' => 1, 'default' => false],
            ],
            'minGuests' => null, 'maxGuests' => null,
        ], $over);
    }

    private function form(array $def, array $over = []): array
    {
        return array_merge(['name' => 'Fiesta Bundle', 'emoji' => '🎈', 'description' => 'Tables, cupcakes and a setup crew.', 'conditions' => '', 'events' => ['Birthday'], 'def' => json_encode($def), 'draft' => '0'], $over);
    }

    public function test_package_form_events_hours_photo_and_inventory(): void
    {
        $cup = $this->cupcakes();
        $this->as('juan@demo.ph', 'supplier')->get('/supplier/packages/new')->assertOk()->assertSee('Rental event')->assertSee('Package photo')->assertSee('Inventory');

        // inventory card: packages the stock covers, and the limiting item
        $stock = $this->postJson('/supplier/packages/price', ['def' => $this->def($cup->id)])->assertOk()->json('stock');
        $this->assertTrue($stock['counts']);
        $this->assertTrue($stock['today'] >= 1 && $stock['today'] <= 10); // never more than the 10 cupcake trays
        $this->assertLessThanOrEqual($stock['today'], $stock['min30']);
        $this->assertStringContainsString('per package', $stock['limit']);

        // a 1×1 PNG, as the browser crop would post it
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->post('/supplier/packages', $this->form($this->def($cup->id), ['events' => ['Birthday', ' baby  shower', 'birthday'], 'min_hours' => 3, 'max_hours' => 8, 'photo' => $png]))
            ->assertSessionHas('success');
        $k = Package::where('name', 'Fiesta Bundle')->firstOrFail();
        $this->assertSame(['Birthday', 'Baby Shower'], $k->events);
        $this->assertSame([3.0, 8.0], [$k->min_hours, $k->max_hours]);
        $this->assertCount(1, $k->images);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($k->images[0]);

        // max below min, and a photo that is not an image, are refused
        $this->put("/supplier/packages/{$k->id}", $this->form($this->def($cup->id), ['min_hours' => 5, 'max_hours' => 2]))->assertSessionHasErrors('max_hours');
        $this->put("/supplier/packages/{$k->id}", $this->form($this->def($cup->id), ['photo' => 'data:image/png;base64,'.base64_encode('not an image')]))->assertSessionHasErrors('photo');
        // editing without a new photo keeps it; "remove" clears it
        $this->put("/supplier/packages/{$k->id}", $this->form($this->def($cup->id)))->assertSessionHas('success');
        $this->assertCount(1, $k->fresh()->images);
        $this->put("/supplier/packages/{$k->id}", $this->form($this->def($cup->id), ['clear_photo' => '1']))->assertSessionHas('success');
        $this->assertSame([], $k->fresh()->images);
    }

    public function test_supplier_builds_a_package_with_components_and_options(): void
    {
        $cup = $this->cupcakes();
        $this->as('juan@demo.ph', 'supplier')->get('/supplier/packages/new')->assertOk()->assertSee('Customer choice');
        $this->get('/supplier/packages/1/edit')->assertOk()->assertSee('Birthday Party Package');

        // live price + preview from the unsaved definition
        $this->postJson('/supplier/packages/price', ['def' => $this->def($cup->id)])->assertOk()->assertJson(['ok' => true, 'package' => 2000]);
        $this->postJson('/supplier/packages/price', ['def' => $this->def($cup->id), 'sel' => ['choices' => [3 => 1], 'addons' => [4]]])->assertJson(['package' => 2850]);
        $this->post('/supplier/packages/preview', ['def' => $this->def($cup->id), 'name' => 'X'])->assertOk()->assertSee('Seating upgrade')->assertSee('Party host');

        $this->post('/supplier/packages', $this->form($this->def($cup->id)))->assertRedirect('/supplier/packages')->assertSessionHas('success');
        $k = Package::where('name', 'Fiesta Bundle')->firstOrFail();
        $this->assertFalse($k->is_draft);
        $this->assertTrue($k->active);
        $this->assertEquals(2000, $k->price);
        $this->assertCount(5, $k->components);
        $this->get('/supplier/packages')->assertOk()->assertSee('Fiesta Bundle')->assertSee('Seating upgrade');

        // validation: someone else's product, unpicked item, one-option choice, nothing inside
        $other = Product::where('supplier_id', '!=', $k->supplier_id)->firstOrFail();
        $bad = fn (array $comps) => $this->form($this->def($cup->id, ['components' => $comps]), ['name' => 'Bad one']);
        $this->post('/supplier/packages', $bad([['kind' => 'rent', 'productId' => $other->id, 'qty' => 1]]))->assertSessionHas('error', fn ($e) => str_contains($e, 'your own listings'));
        $this->post('/supplier/packages', $bad([['kind' => 'rent', 'productId' => null, 'qty' => 1]]))->assertSessionHas('error', fn ($e) => str_contains($e, 'choose an item'));
        $this->post('/supplier/packages', $bad([['kind' => 'choice', 'label' => 'X', 'options' => [['productId' => 1, 'qty' => 1]]]]))->assertSessionHas('error');
        $this->post('/supplier/packages', $bad([]))->assertSessionHas('error');
        $this->assertFalse(Package::where('name', 'Bad one')->exists());

        // drafts stay hidden from customers
        $this->post('/supplier/packages', $this->form($this->def($cup->id), ['name' => 'Draft Bundle', 'draft' => '1']))->assertSessionHas('success');
        $d = Package::where('name', 'Draft Bundle')->firstOrFail();
        $this->assertTrue($d->is_draft);
        $this->get('/packages/'.$d->id)->assertNotFound();
    }

    public function test_package_list_actions(): void
    {
        $this->as('juan@demo.ph', 'supplier');
        $k = Package::findOrFail(1);

        $this->post("/supplier/packages/{$k->id}/duplicate")->assertRedirect();
        $copy = Package::where('name', 'Copy of '.$k->name)->firstOrFail();
        $this->assertTrue($copy->is_draft);
        $this->assertFalse($copy->active);
        $this->post("/supplier/packages/{$copy->id}/active")->assertSessionHas('error'); // drafts are published from the editor

        $order = fn () => Package::where('supplier_id', $k->supplier_id)->orderBy('sort')->orderBy('id')->pluck('id')->all();
        $before = $order();
        $this->post("/supplier/packages/{$before[1]}/move", ['dir' => 'up']);
        $this->assertSame([$before[1], $before[0]], array_slice($order(), 0, 2));

        $this->post("/supplier/packages/{$k->id}/active")->assertSessionHas('success');
        $this->assertFalse($k->fresh()->active);
        $this->get('/packages/'.$k->id)->assertNotFound();
        $this->post("/supplier/packages/{$k->id}/active");
        $this->assertTrue($k->fresh()->active);

        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        CartLine::create(['user_id' => $maria->id, 'supplier_id' => $k->supplier_id, 'type' => 'package', 'ref_id' => $k->id, 'days' => [add_days(today_iso(), 9) => 1]]);
        $this->delete("/supplier/packages/{$k->id}")->assertRedirect('/supplier/packages');
        $this->assertNull(Package::find($k->id));
        $this->assertSame(0, CartLine::where('type', 'package')->where('ref_id', $k->id)->count());

        // another supplier cannot touch it
        $rival = \App\Models\Supplier::where('id', '!=', $k->supplier_id)->where('status', 'approved')->firstOrFail();
        $this->actingAs(User::findOrFail($rival->owner_id))->withSession(['mode' => 'supplier'])->post("/supplier/packages/{$copy->id}/duplicate")->assertNotFound();
        $this->delete("/supplier/packages/{$copy->id}")->assertNotFound();
    }

    public function test_customer_picks_options_and_booking_keeps_its_snapshot(): void
    {
        $cup = $this->cupcakes();
        $this->as('juan@demo.ph', 'supplier')->post('/supplier/packages', $this->form($this->def($cup->id)));
        $k = Package::where('name', 'Fiesta Bundle')->firstOrFail();
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        CartLine::where('user_id', $maria->id)->delete();

        $this->as('maria@demo.ph', 'customer')->get('/packages/'.$k->id)->assertOk()->assertSee('Seating upgrade')->assertSee('Party host')->assertSee('Consumable');
        $sel = ['choices' => [3 => 1], 'addons' => [4]];
        $this->postJson('/api/package-quote', ['package_id' => $k->id, 'sel' => $sel])->assertOk()
            ->assertJson(['package' => 2850, 'perDay' => 2050, 'once' => 800]);

        // scheduler: rentals per day, consumables + services once
        $d1 = add_days(today_iso(), 12);
        $d2 = add_days(today_iso(), 13);
        $this->postJson('/api/schedule', ['type' => 'package', 'ref_id' => $k->id, 'days' => [$d1 => 1, $d2 => 1], 'sel' => $sel])
            ->assertOk()->assertJson(['ok' => true, 'total' => 2 * 2050 + 800]); // fixed price covers cupcakes + crew; the add-on is once

        $this->post('/cart', ['type' => 'package', 'ref_id' => $k->id, 'days' => json_encode([$d1 => 1, $d2 => 1]), 'sel' => json_encode($sel)])->assertSessionHas('success');
        $line = CartLine::where('user_id', $maria->id)->firstOrFail();
        $this->assertSame([3 => 1], array_map('intval', $line->sel['choices']));
        $this->assertSame([4], $line->sel['addons']);

        $this->checkout($k->supplier_id, [
            'eventName' => 'Fiesta', 'eventType' => 'Birthday', 'eventDate' => $d1, 'fulfillment' => 'pickup',
            'contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101', 'arrangement' => 'full', 'method' => 'cod',
        ])->assertOk()->assertJsonPath('ok', true);
        $r = RentalRequest::latest('id')->firstOrFail();
        $total = $r->pricing['total'];
        $this->assertEquals(4900.0, $total);
        $this->assertSame('Fiesta Bundle', $r->lines[0]['snap']['name']);

        // the supplier reprices the package: the booked request keeps its price and contents
        $this->as('juan@demo.ph', 'supplier')->put("/supplier/packages/{$k->id}", $this->form($this->def($cup->id, ['pricing' => ['mode' => 'fixed', 'price' => 9999]])))->assertSessionHas('success');
        inv()->flush();
        $this->assertEquals(9999, $k->fresh()->price);
        $this->assertEquals($total, \App\Domain\Pricing::quoteRequest($r->fresh())['total']);
    }
}
