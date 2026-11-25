<?php

namespace Tests\Feature;

use App\Models\CartLine;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Checkout quantity field: stock-checked changes, clear errors, and splitting between locations. */
class CheckoutQuantityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function setUpLine(int $qty = 3): array
    {
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $shop = Supplier::where('owner_id', User::where('email', 'juan@demo.ph')->value('id'))->firstOrFail();
        $chair = Product::where('supplier_id', $shop->id)->where('status', 'active')->where('listing_type', 'rent')->orderBy('id')->firstOrFail();
        $d1 = add_days(today_iso(), 20);
        $d2 = add_days(today_iso(), 21);
        CartLine::where('user_id', $maria->id)->delete();
        $this->actingAs($maria)->withSession(['mode' => 'customer']);
        $this->post('/cart', ['type' => 'item', 'ref_id' => $chair->id, 'days' => json_encode([$d1 => $qty, $d2 => $qty]), 'then' => 'checkout']);
        $line = CartLine::where('user_id', $maria->id)->where('direct', true)->firstOrFail();

        return [$maria, $shop, $chair, $line, $d1, $d2];
    }

    public function test_quantity_change_is_stock_checked_and_saved(): void
    {
        [$maria, , $chair, $line, $d1, $d2] = $this->setUpLine();
        $free = inv()->availableOn($chair->id, $d1);

        // more than the stock: refused with a clear message and what IS available
        $this->postJson('/checkout/qty', ['cart_id' => $line->id, 'days' => [$d1 => $free + 5, $d2 => $free + 5]])
            ->assertStatus(422)->assertJsonPath('ok', false)
            ->assertJsonPath('available.'.$d1, $free)
            ->assertJson(fn ($j) => $j->where('error', fn ($e) => str_contains($e, 'Only '.$free.' × '.$chair->name) && str_contains($e, 'you asked for '.($free + 5)))->etc());
        $this->assertSame([$d1 => 3, $d2 => 3], $line->fresh()->days);

        // a valid change is saved on the cart line
        $this->postJson('/checkout/qty', ['cart_id' => $line->id, 'days' => [$d1 => 6, $d2 => 4]])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame([$d1 => 6, $d2 => 4], $line->fresh()->days);

        // zero, other dates, or someone else's line
        $this->postJson('/checkout/qty', ['cart_id' => $line->id, 'days' => [$d1 => 0, $d2 => 4]])->assertStatus(422);
        $this->postJson('/checkout/qty', ['cart_id' => $line->id, 'days' => [add_days($d1, 5) => 2]])->assertStatus(422)->assertJsonPath('ok', false);
        $this->actingAs(User::where('email', 'bea@demo.ph')->firstOrFail())->postJson('/checkout/qty', ['cart_id' => $line->id, 'days' => [$d1 => 1, $d2 => 1]])->assertNotFound();
        $this->assertSame([$d1 => 6, $d2 => 4], $line->fresh()->days);
    }

    public function test_changed_quantity_split_between_two_locations_is_submitted(): void
    {
        [$maria, $shop, , $line, $d1, $d2] = $this->setUpLine();
        $this->postJson('/checkout/qty', ['cart_id' => $line->id, 'days' => [$d1 => 5, $d2 => 5]])->assertOk();
        $dest = fn ($id, $label) => ['id' => $id, 'label' => $label, 'address' => '12 Sampaguita St., Brgy. Holy Spirit, Quezon City', 'zoneId' => '', 'contactName' => 'Ana Cruz', 'contactPhone' => '0917 555 0303'];

        // the parts must add up to the (new) quantity
        $order = ['supplier_id' => $shop->id, 'cart_line_ids' => [$line->id], 'eventDate' => $d1, 'fulfillment' => 'delivery', 'plan' => 'full', 'method' => 'online',
            'destinations' => [$dest('d1', 'Church'), $dest('d2', 'Reception')]];
        $post = fn (array $lines) => $this->postJson('/checkout', ['shared' => ['contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101'], 'agree' => 1,
            'orders' => [$order + ['lines' => $lines]]]);
        $post([['cart_id' => $line->id, 'type' => 'item', 'days' => [$d1 => 3, $d2 => 3], 'destId' => 'd1'], ['cart_id' => $line->id, 'type' => 'item', 'days' => [$d1 => 3, $d2 => 3], 'destId' => 'd2']])
            ->assertStatus(422);
        $post([['cart_id' => $line->id, 'type' => 'item', 'days' => [$d1 => 3, $d2 => 3], 'destId' => 'd1'], ['cart_id' => $line->id, 'type' => 'item', 'days' => [$d1 => 2, $d2 => 2], 'destId' => 'd2']])
            ->assertOk()->assertJson(['ok' => true]);

        $r = RentalRequest::where('customer_id', $maria->id)->latest('id')->firstOrFail();
        $this->assertCount(2, $r->lines);
        $this->assertSame(5, collect($r->lines)->sum(fn ($l) => $l['days'][$d1]));
        $this->assertEqualsCanonicalizing(['d1', 'd2'], array_column($r->lines, 'destId'));
    }
}
