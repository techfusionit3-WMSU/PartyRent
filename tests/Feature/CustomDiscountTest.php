<?php

namespace Tests\Feature;

use App\Domain\Discounts;
use App\Models\CartLine;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Custom discounts a shop gives one customer (family, regulars): made by the owner, applied on that customer's rental. */
class CustomDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private User $liza;

    private User $maria;

    private User $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Supplier::where('name', 'Elegant Occasion Rentals')->firstOrFail();
        $this->liza = User::where('email', 'liza@demo.ph')->firstOrFail();
        $this->maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->jose = User::where('email', 'jose@demo.ph')->firstOrFail();
    }

    /** A new pending pickup rental of $qty gold chairs from Elegant Occasion for $u. */
    private function rental(User $u, int $qty = 10, int $day = 45): RentalRequest
    {
        CartLine::where('user_id', $u->id)->delete();
        $chair = Product::where('supplier_id', $this->s->id)->where('name', 'Gold Chiavari Chair')->firstOrFail();
        $this->actingAs($u)->withSession(['mode' => 'customer'])
            ->post('/cart', ['type' => 'item', 'ref_id' => $chair->id, 'days' => json_encode([add_days(today_iso(), $day) => $qty])])->assertSessionHas('success');
        $this->checkout($this->s->id, ['contactName' => $u->name, 'contactPhone' => '0917 555 0101', 'fulfillment' => 'pickup', 'plan' => 'standard'])->assertOk();

        return RentalRequest::where('customer_id', $u->id)->latest('id')->firstOrFail();
    }

    private function asLiza(): static
    {
        return $this->actingAs($this->liza)->withSession(['mode' => 'supplier']);
    }

    private function make(array $x): Discount
    {
        $this->asLiza()->post(route('supplier.discounts.custom.store'), array_merge(['name' => 'Family discount', 'kind' => 'order_percent', 'value' => 20], $x))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        return Discount::whereNotNull('customer_id')->latest('id')->firstOrFail();
    }

    public function test_owner_creates_custom_discounts_kept_apart_from_promotions(): void
    {
        $r = $this->rental($this->maria);
        $this->asLiza()->get(route('supplier.discounts'))->assertOk()->assertSee('Custom discounts for selected customers')->assertSee('Private')
            ->assertSee('<option value="'.$this->maria->id.'"', false); // Maria rented from the shop: in the picker
        $pct = $this->make(['customer_id' => $this->maria->id, 'note' => 'My cousin', 'max_uses' => 1]);
        $this->assertSame([$this->maria->id, 'order_percent', 20.0, 1], [$pct->customer_id, $pct->kind, $pct->value, $pct->max_uses]);
        // someone else by exact email
        $fixed = $this->make(['customer_email' => 'JOSE@demo.ph', 'name' => 'Godchild', 'kind' => 'order_amount', 'value' => 150]);
        $this->assertSame($this->jose->id, $fixed->customer_id);

        // validation: an unknown email, no customer, zero / negative / over 100%
        $this->asLiza()->post(route('supplier.discounts.custom.store'), ['name' => 'Extra', 'kind' => 'order_percent', 'value' => 10, 'customer_email' => 'nobody@nowhere.ph'])->assertSessionHas('error', fn ($e) => str_contains($e, 'No PartyRent customer'));
        $this->asLiza()->post(route('supplier.discounts.custom.store'), ['name' => 'Extra', 'kind' => 'order_percent', 'value' => 10])->assertSessionHas('error', 'Choose who gets this discount.');
        $this->asLiza()->post(route('supplier.discounts.custom.store'), ['name' => 'Extra', 'kind' => 'order_percent', 'value' => 0, 'customer_id' => $this->maria->id])->assertSessionHasErrors('value');
        $this->asLiza()->post(route('supplier.discounts.custom.store'), ['name' => 'Extra', 'kind' => 'order_amount', 'value' => -50, 'customer_id' => $this->maria->id])->assertSessionHasErrors('value');
        $this->asLiza()->post(route('supplier.discounts.custom.store'), ['name' => 'Extra', 'kind' => 'order_percent', 'value' => 120, 'customer_id' => $this->maria->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'at most 100%'));

        // never applied automatically like a promotion, and never offered to other customers
        $this->assertTrue(Discounts::running($this->s->id, today_iso())->every(fn ($d) => $d->customer_id === null));
        $this->assertArrayNotHasKey('customDiscount', $r->fresh()->pricing);
        $this->assertSame(0.0, (float) $r->fresh()->pricing['discount']);
        // the promotions form can't touch a custom discount
        $this->asLiza()->put(route('supplier.discounts.update', $pct), ['name' => 'Hack', 'kind' => 'percent', 'value' => 5, 'applies_to' => 'all'])->assertNotFound();
    }

    public function test_apply_percent_discount_and_payments_follow(): void
    {
        $r = $this->rental($this->maria);
        $d = $this->make(['customer_id' => $this->maria->id]);
        $before = $r->pricing;
        $this->asLiza()->get(route('supplier.requests.show', $r))->assertOk()->assertSee('Customer discount')->assertSee('Family discount · 20% off the rental');

        $this->post(route('supplier.requests.custom-discount', $r), ['discount_id' => $d->id])->assertSessionHas('success');
        $p = $r->fresh()->pricing;
        $amt = round($before['rentalFee'] * 0.2, 2);
        $this->assertEqualsWithDelta($amt, $p['customDiscount']['amount'], 0.001);
        $this->assertEqualsWithDelta($before['total'] - $amt, $p['total'], 0.001);
        $this->assertEqualsWithDelta($p['total'], $p['payable'], 0.001);
        $this->assertEqualsWithDelta(round($p['total'] * $p['terms']['downPct'] / 100, 2), $p['downPayment'], 0.001); // payments follow the new total
        $this->assertEqualsWithDelta($p['total'] - $p['downPayment'], $p['finalBalance'], 0.001);
        $this->assertEquals([$d->id, 'Liza Navarro', 20], [$r->fresh()->custom_discount_id, $p['customDiscount']['appliedBy'], $p['customDiscount']['value']]);
        $this->assertEqualsWithDelta($before['total'], $p['customDiscount']['totalBefore'], 0.001);

        // shown to the customer in the breakdown, and they were told
        $this->actingAs($this->maria)->withSession(['mode' => 'customer'])->get(route('rentals.show', $r))->assertOk()
            ->assertSee('Special discount – Family discount')->assertSee('−'.money($amt));
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->maria->id, 'title' => 'You got a discount on '.$r->code]);
        $this->assertSame((float) $p['total'], $r->fresh()->fin()['payable']);

        // only once per rental
        $this->asLiza()->post(route('supplier.requests.custom-discount', $r), ['discount_id' => $d->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'already has a custom discount'));

        // editing or turning the discount off later never changes this rental
        $this->put(route('supplier.discounts.custom.update', $d), ['name' => 'Family discount', 'kind' => 'order_percent', 'value' => 50, 'customer_id' => $this->maria->id])->assertSessionHas('success');
        $this->post(route('supplier.discounts.custom.active', $d))->assertSessionHas('success');
        $this->assertEqualsWithDelta($amt, $r->fresh()->pricing['customDiscount']['amount'], 0.001);
        $this->assertEqualsWithDelta(20.0, $r->fresh()->pricing['customDiscount']['value'], 0.001);
        // used on a rental: can't be deleted (stays on record)
        $this->delete(route('supplier.discounts.custom.delete', $d))->assertSessionHas('error');

        // remove it again (still before any payment): the price goes back
        $this->delete(route('supplier.requests.custom-discount.remove', $r))->assertSessionHas('success');
        $this->assertEqualsWithDelta($before['total'], $r->fresh()->pricing['total'], 0.001);
        $this->assertArrayNotHasKey('customDiscount', $r->fresh()->pricing);
        $this->assertNull($r->fresh()->custom_discount_id);
    }

    public function test_fixed_amount_limits_and_eligibility(): void
    {
        $r = $this->rental($this->maria, 2); // 2 chairs: a small rental
        $base = (float) $r->pricing['rentalFee'];
        $big = $this->make(['customer_id' => $this->maria->id, 'name' => 'Too big', 'kind' => 'order_amount', 'value' => $base + 500]);
        $this->asLiza()->post(route('supplier.requests.custom-discount', $r), ['discount_id' => $big->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'more than the rental items'));
        $this->assertArrayNotHasKey('customDiscount', $r->fresh()->pricing);

        $ok = $this->make(['customer_id' => $this->maria->id, 'name' => 'Peso off', 'kind' => 'order_amount', 'value' => 50, 'max_uses' => 1]);
        $this->asLiza()->post(route('supplier.requests.custom-discount', $r), ['discount_id' => $ok->id])->assertSessionHas('success');
        $this->assertEqualsWithDelta($r->pricing['total'] - 50, $r->fresh()->pricing['total'], 0.001);

        // used up (one rental), so not on her next rental
        $r2 = $this->rental($this->maria, 3, 50);
        $this->asLiza()->post(route('supplier.requests.custom-discount', $r2), ['discount_id' => $ok->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'used on 1 rental'));

        // another customer's rental: refused, even by a direct request
        $jr = $this->rental($this->jose, 3, 55);
        $this->asLiza()->post(route('supplier.requests.custom-discount', $jr), ['discount_id' => $big->id])->assertSessionHas('error', 'This discount is for another customer.');
        $this->assertArrayNotHasKey('customDiscount', $jr->fresh()->pricing);

        // turned off / not started yet
        $off = $this->make(['customer_id' => $this->maria->id, 'name' => 'Later', 'kind' => 'order_percent', 'value' => 5, 'starts_on' => add_days(today_iso(), 10)]);
        $this->asLiza()->post(route('supplier.requests.custom-discount', $r2), ['discount_id' => $off->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'starts on'));

        // once a payment is in, the price can't change
        $pct = $this->make(['customer_id' => $this->maria->id, 'name' => 'Ten', 'kind' => 'order_percent', 'value' => 10]);
        Payment::create(['code' => 'PAY-T1', 'rental_request_id' => $r2->id, 'type' => 'down', 'method' => 'online', 'amount' => 100, 'date' => today_iso(), 'submitted_at' => now(), 'status' => 'pending', 'reference' => 'T1']);
        $this->asLiza()->post(route('supplier.requests.custom-discount', $r2), ['discount_id' => $pct->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'already paid'));
    }

    public function test_permissions_and_admin_audit(): void
    {
        $r = $this->rental($this->maria);
        $d = $this->make(['customer_id' => $this->maria->id]);

        // another shop: can't see, edit, toggle or apply it, nor apply it to its own rental
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $this->put(route('supplier.discounts.custom.update', $d), ['name' => 'Mine', 'kind' => 'order_percent', 'value' => 90, 'customer_id' => $this->maria->id])->assertNotFound();
        $this->post(route('supplier.discounts.custom.active', $d))->assertNotFound();
        $this->post(route('supplier.requests.custom-discount', $r), ['discount_id' => $d->id])->assertNotFound();
        $this->get(route('supplier.discounts'))->assertOk()->assertDontSee(route('supplier.discounts.custom.update', $d), false);

        // shop staff without the owner permission (cashier) can't make or apply custom discounts
        $grace = User::where('email', 'cashier@elegantoccasion.ph')->firstOrFail();
        $this->actingAs($grace)->withSession(['mode' => 'supplier']);
        $this->post(route('supplier.discounts.custom.store'), ['name' => 'Staff', 'kind' => 'order_percent', 'value' => 50, 'customer_id' => $this->maria->id])->assertSessionMissing('success');
        $this->post(route('supplier.requests.custom-discount', $r), ['discount_id' => $d->id])->assertSessionMissing('success');
        $this->assertArrayNotHasKey('customDiscount', $r->fresh()->pricing);
        $this->assertSame(1, Discount::whereNotNull('customer_id')->count());

        // a customer can't create or apply one (no supplier access)
        $this->actingAs($this->maria)->withSession(['mode' => 'customer']);
        $this->post(route('supplier.discounts.custom.store'), ['name' => 'Me', 'kind' => 'order_percent', 'value' => 100, 'customer_id' => $this->maria->id])->assertSessionMissing('success');
        $this->post(route('supplier.requests.custom-discount', $r), ['discount_id' => $d->id])->assertSessionMissing('success');
        $this->assertSame(1, Discount::whereNotNull('customer_id')->count());
        $this->assertArrayNotHasKey('customDiscount', $r->fresh()->pricing);

        // applied by the owner, then audited by an admin
        $this->asLiza()->post(route('supplier.requests.custom-discount', $r), ['discount_id' => $d->id])->assertSessionHas('success');
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->get(route('admin.custom-discounts'))->assertOk()
            ->assertSee('Family discount')->assertSee('Elegant Occasion Rentals')->assertSee('Maria Santos')->assertSee($r->code)->assertSee('by Liza Navarro');
        $this->get(route('admin.transactions'))->assertOk()->assertSee('special discount');
        $this->assertDatabaseHas('audit_logs', ['action' => 'Applied custom discount']);
    }
}
