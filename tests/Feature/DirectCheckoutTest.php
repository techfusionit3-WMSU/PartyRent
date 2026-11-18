<?php

namespace Tests\Feature;

use App\Models\CartLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Request Rental" on a listing checks out exactly that item, apart from the Rental List. */
class DirectCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_request_rental_checks_out_only_that_item_and_leaves_the_list_alone(): void
    {
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $shop = Supplier::where('owner_id', User::where('email', 'juan@demo.ph')->value('id'))->first();
        [$table, $chair] = Product::where('supplier_id', $shop->id)->where('status', 'active')->orderBy('id')->take(2)->get()->all();
        $d = add_days(today_iso(), 20);
        CartLine::where('user_id', $maria->id)->delete();
        $this->actingAs($maria)->withSession(['mode' => 'customer']);

        // the Rental List already has both items from this shop
        $this->post('/cart', ['type' => 'item', 'ref_id' => $table->id, 'days' => json_encode([$d => 2])])->assertSessionHas('success');
        $this->post('/cart', ['type' => 'item', 'ref_id' => $chair->id, 'days' => json_encode([$d => 3])])->assertSessionHas('success');

        // Request Rental on the chair's page with a different quantity
        $res = $this->post('/cart', ['type' => 'item', 'ref_id' => $chair->id, 'days' => json_encode([$d => 5]), 'then' => 'checkout']);
        $direct = CartLine::where('user_id', $maria->id)->where('direct', true)->firstOrFail();
        $res->assertRedirect(route('checkout', ['line' => $direct->id]));
        $this->assertSame([$d => 5], $direct->days);

        // the list is unchanged (no merge into the chair line, no extra line counted)
        $this->assertSame(3, CartLine::where('user_id', $maria->id)->where('direct', false)->where('ref_id', $chair->id)->first()->days[$d]);
        $this->assertSame(2, \App\Domain\Rentals::cartCount($maria));

        // the checkout page holds only the chair
        $page = $this->get(route('checkout', ['line' => $direct->id]))->assertOk();
        $orders = $page->viewData('orders');
        $this->assertCount(1, $orders);
        $this->assertSame([$direct->id], $orders[0]['cart_line_ids']);

        // submitting it removes only the direct line
        $this->postJson('/checkout', [
            'shared' => ['contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101'], 'agree' => 1,
            'orders' => [['supplier_id' => $shop->id, 'cart_line_ids' => [$direct->id], 'eventDate' => $d, 'fulfillment' => 'pickup', 'plan' => 'full', 'method' => 'online']],
        ])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, CartLine::where('user_id', $maria->id)->where('direct', true)->count());
        $this->assertSame(2, \App\Domain\Rentals::cartCount($maria));
    }
}
