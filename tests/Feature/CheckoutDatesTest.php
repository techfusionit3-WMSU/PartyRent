<?php

namespace Tests\Feature;

use App\Models\CartLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feedback 2026-11-22: the checkout shows the dates set on the item and lets the customer change them there
 * (stock checked first, nothing changes when it does not fit); the new dates become the rental period everywhere.
 */
class CheckoutDatesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function line(int $qty): array
    {
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $shop = Supplier::where('owner_id', User::where('email', 'juan@demo.ph')->value('id'))->firstOrFail();
        $item = Product::where('supplier_id', $shop->id)->where('status', 'active')->where('listing_type', 'rent')->orderBy('id')->firstOrFail();
        $d = add_days(today_iso(), 20);
        CartLine::where('user_id', $maria->id)->delete();
        $this->actingAs($maria)->withSession(['mode' => 'customer']);
        $this->post('/cart', ['type' => 'item', 'ref_id' => $item->id, 'days' => json_encode([$d => $qty]), 'then' => 'checkout']);

        return [CartLine::where('user_id', $maria->id)->where('direct', true)->firstOrFail(), $item, $d];
    }

    public function test_checkout_shows_the_item_dates_and_changes_them_in_place(): void
    {
        [$line, , $d] = $this->line(2);
        $this->get('/checkout?line='.$line->id)->assertOk()->assertSee(fmt_date($d))->assertDontSee('No rental dates selected');
        // the dates picked on the item became the rental period
        $this->assertSame(['start' => $d, 'end' => $d], \App\Support\RentalPeriod::get());

        $a = add_days($d, 3);
        $b = add_days($d, 4);
        $this->postJson('/checkout/dates', ['cart_ids' => [$line->id], 'start' => $a, 'end' => $b])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('orders.0.first', $a)->assertJsonPath('orders.0.last', $b)
            ->assertJsonPath('label', fmt_range($a, $b));
        $this->assertSame([$a => 2, $b => 2], $line->fresh()->days);
        $this->assertSame(['start' => $a, 'end' => $b], \App\Support\RentalPeriod::get());
        // the item page opens on the same dates
        $this->get(route('products.show', $line->ref_id))->assertOk()->assertSee('value="'.$a.'"', false)->assertSee('value="'.$b.'"', false);
    }

    public function test_dates_without_enough_stock_change_nothing(): void
    {
        // a day on which a demo booking already holds some of an item's stock
        $busy = null;
        foreach (Product::where('status', 'active')->where('listing_type', 'rent')->get() as $p) {
            for ($i = 1; $i <= 60 && ! $busy; $i++) {
                $day = add_days(today_iso(), $i);
                if (inv()->availableOn($p->id, $day) < (int) $p->total_stock) {
                    $busy = [$p, $day];
                }
            }
            if ($busy) {
                break;
            }
        }
        $this->assertNotNull($busy, 'demo data has bookings');
        [$item, $day] = $busy;
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $start = add_days(today_iso(), 70);
        $line = CartLine::create(['user_id' => $maria->id, 'supplier_id' => $item->supplier_id, 'type' => 'item', 'ref_id' => $item->id, 'days' => [$start => (int) $item->total_stock], 'selected' => true]);
        $this->actingAs($maria)->withSession(['mode' => 'customer'])
            ->postJson('/checkout/dates', ['cart_ids' => [$line->id], 'start' => $day, 'end' => $day])
            ->assertStatus(422)->assertJsonPath('ok', false)
            ->assertJson(fn ($j) => $j->where('error', fn ($e) => str_contains($e, 'Your dates were not changed'))->etc());
        $this->assertSame([$start => (int) $item->total_stock], $line->fresh()->days);
    }

    public function test_past_dates_and_other_peoples_items_are_refused(): void
    {
        [$line, , $d] = $this->line(1);
        $this->postJson('/checkout/dates', ['cart_ids' => [$line->id], 'start' => add_days(today_iso(), -1), 'end' => today_iso()])->assertStatus(422);
        $this->actingAs(User::where('email', 'bea@demo.ph')->firstOrFail())
            ->postJson('/checkout/dates', ['cart_ids' => [$line->id], 'start' => $d, 'end' => $d])->assertNotFound();
    }
}
