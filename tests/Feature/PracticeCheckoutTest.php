<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** PAYMONGO_FAKE=true: a built-in practice checkout stands in for PayMongo (no account, no real money). */
class PracticeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paymongo.secret_key' => '', 'services.paymongo.fake' => true]);
        Http::preventStrayRequests(); // practice mode must never call PayMongo
    }

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    private function start(): array
    {
        $r = RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $amt = $r->fin()['upfront']['amount'];
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertSee('Pay online')->assertSee('Practice mode');
        $res = $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $p = Payment::where('gateway', 'paymongo')->latest('id')->firstOrFail();
        $res->assertRedirect(route('paymongo.practice', $p->gateway_ref));

        return [$r, $p, $amt];
    }

    public function test_practice_payment_is_verified_like_a_real_one(): void
    {
        [$r, $p, $amt] = $this->start();
        $this->assertSame('awaiting', $p->status);
        $this->get('/practice-checkout/'.$p->gateway_ref)->assertOk()->assertSee('Practice mode')->assertSee(money($amt))->assertSee('GCash');

        // a failed attempt charges nothing and stays on the page
        $this->post('/practice-checkout/'.$p->gateway_ref, ['action' => 'fail'])->assertSessionHas('error');
        $this->assertSame('awaiting', $p->fresh()->status);

        // pay with Maya → back to the normal return URL → verified automatically
        $this->post('/practice-checkout/'.$p->gateway_ref, ['action' => 'pay', 'method' => 'paymaya'])
            ->assertRedirect(route('rentals.paymongo.return', [$r, $p]));
        $this->get(route('rentals.paymongo.return', [$r, $p]))->assertSessionHas('success', fn ($m) => str_contains($m, 'verified'));
        $p->refresh();
        $this->assertSame('verified', $p->status);
        $this->assertStringStartsWith('pay_practice_', $p->reference);
        $this->assertStringContainsString('Maya', $p->notes);
        $this->assertNotNull($p->receipt_no);
        $this->assertTrue($r->fresh()->fin()['upfrontPaid']);
        $this->assertTrue(AppNotification::where('user_id', $r->supplier->owner_id)->where('title', 'like', 'Down payment received%')->exists());

        // the supplier can now accept; the finished checkout page just sends the customer back
        $this->as('juan@demo.ph', 'supplier')->post('/supplier/requests/PR-0003/accept');
        $this->as('jose@demo.ph', 'customer')->get('/practice-checkout/'.$p->gateway_ref)->assertRedirect('/rentals/PR-0003');
    }

    public function test_cancel_discards_and_others_cannot_open_it(): void
    {
        [$r, $p] = $this->start();
        $this->as('maria@demo.ph', 'customer')->get('/practice-checkout/'.$p->gateway_ref)->assertNotFound();
        $this->get('/practice-checkout/cs_practice_nope')->assertNotFound();

        $this->as('jose@demo.ph', 'customer')->post('/practice-checkout/'.$p->gateway_ref, ['action' => 'cancel'])
            ->assertRedirect(route('rentals.paymongo.return', [$r, $p, 'cancelled' => 1]));
        $this->get(route('rentals.paymongo.return', [$r, $p, 'cancelled' => 1]))->assertSessionHas('warn');
        $this->assertNull(Payment::find($p->id));
    }

    /** Checkout asks for the first payment right away instead of just saving the request. */
    public function test_checkout_goes_straight_to_payment(): void
    {
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        \App\Models\CartLine::where('user_id', $maria->id)->delete();
        $shop = \App\Models\Supplier::where('owner_id', User::where('email', 'juan@demo.ph')->value('id'))->firstOrFail();
        $table = \App\Models\Product::where('supplier_id', $shop->id)->where('name', 'like', 'Rectangular%')->firstOrFail(); // ₱180/day
        $d = add_days(today_iso(), 20);
        $form = fn (array $over) => array_merge(['eventName' => 'Pay Now Party', 'eventType' => 'Birthday', 'eventDate' => $d, 'fulfillment' => 'pickup',
            'contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101', 'arrangement' => 'full', 'method' => 'bank', 'agree' => '1'], $over);

        $this->as('maria@demo.ph', 'customer')->post('/cart', ['type' => 'item', 'ref_id' => $table->id, 'days' => json_encode([$d => 5])]);
        $this->get('/checkout/'.$shop->id.'?arrangement=full')->assertOk()->assertSee('Pay now to confirm')->assertSee('Place request')->assertSee('Pay online')
            ->assertSee('data-due-cod="Down payment (rest on delivery)|', false);

        // online: the request is saved and the customer lands on the checkout for the full ₱900
        $res = $this->post('/checkout/'.$shop->id, $form(['payWith' => 'online']));
        $r = RentalRequest::where('event_name', 'Pay Now Party')->firstOrFail();
        $p = Payment::where('rental_request_id', $r->id)->firstOrFail();
        $res->assertRedirect(route('paymongo.practice', $p->gateway_ref));
        $this->assertEquals([900.0, 'full', 'awaiting'], [$p->amount, $p->type, $p->status]);

        // transfer: back to the request with the payment form opened
        $this->post('/cart', ['type' => 'item', 'ref_id' => $table->id, 'days' => json_encode([$d => 2])]);
        $this->post('/checkout/'.$shop->id, $form(['eventName' => 'Transfer Party', 'payWith' => 'transfer']))
            ->assertRedirect(route('rentals.show', ['rentalRequest' => RentalRequest::where('event_name', 'Transfer Party')->value('code'), 'pay' => 1]))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Pay the full payment of ₱360.00'));
    }

    public function test_never_runs_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->assertFalse(\App\Domain\PayMongo::practice());
        $this->assertFalse(\App\Domain\PayMongo::enabled());
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertDontSee('Pay online');
        $this->get('/practice-checkout/cs_practice_x')->assertNotFound();
    }
}
