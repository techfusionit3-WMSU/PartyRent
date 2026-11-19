<?php

namespace Tests\Feature;

use App\Domain\BookingDays;
use App\Domain\Payouts;
use App\Domain\Pricing;
use App\Domain\Refunds;
use App\Domain\Shortfalls;
use App\Domain\Xendit;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Change list C13: refunds go back through the gateway (Xendit / practice) and lower the supplier payout. */
class RefundsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    /** An accepted booking (₱10 × 50 chairs) fully paid online through $gateway. */
    private function paidOnline(string $gateway, string $ref = 'inv_77'): RentalRequest
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $p = Product::create([
            'supplier_id' => $juan->supplier->id, 'category_id' => Product::where('supplier_id', $juan->supplier->id)->value('category_id'), 'name' => 'Refund Chair',
            'description' => 'x', 'total_stock' => 100, 'unit' => 'piece / day', 'tiers' => [['min' => 1, 'max' => null, 'price' => 10]],
            'colors' => [], 'themes' => [], 'events' => [], 'images' => [], 'status' => 'active', 'listing_type' => 'rent',
        ]);
        $d = add_days(today_iso(), 30);
        $r = RentalRequest::create([
            'code' => 'PR-R'.random_int(100, 999), 'customer_id' => User::where('email', 'maria@demo.ph')->value('id'), 'supplier_id' => $p->supplier_id,
            'event_name' => 'Refund Party', 'event_type' => 'Birthday', 'event_date' => $d, 'start_date' => $d, 'end_date' => $d, 'fulfillment' => 'pickup',
            'lines' => [['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => [$d => 50]]], 'arrangement' => 'full', 'method' => 'online', 'plan' => 'full',
            'status' => 'accepted', 'pricing' => [], 'accepted_at' => today_iso(), 'reserved_at' => now(),
        ]);
        $r->update(['pricing' => Pricing::quoteRequest($r->fresh())]);
        Payment::create(['code' => 'PAY-R1', 'rental_request_id' => $r->id, 'type' => 'full', 'method' => 'online', 'gateway' => $gateway, 'gateway_ref' => $ref,
            'amount' => $r->pricing['total'], 'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'X1', 'status' => 'verified', 'verified_at' => now()]);
        BookingDays::sync($r->fresh());
        inv()->flush();

        return $r->fresh();
    }

    private function pendingRefund(RentalRequest $r, float $amount): Payment
    {
        return Payment::create(['code' => 'PAY-RF'.random_int(100, 999), 'rental_request_id' => $r->id, 'type' => 'refund', 'method' => 'bank', 'amount' => $amount,
            'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'RF', 'status' => 'pending', 'recorded_by' => 'system', 'notes' => 'test']);
    }

    public function test_practice_mode_refund_through_a_resolved_case_lowers_the_payout(): void
    {
        config(['services.xendit.secret_key' => '', 'services.paymongo.secret_key' => '', 'services.paymongo.fake' => true]);
        $r = $this->paidOnline('paymongo');
        $before = Payouts::breakdown($r);
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->actingAs($juan);
        $res = Shortfalls::report($juan, $juan->supplier, ['product_id' => $r->lines[0]['ref_id'], 'qty' => 60, 'dates' => [$r->start_date], 'reason' => 'Lost in a fire.']);
        $c = $res['cases'][0];
        Shortfalls::propose($c, $juan, 'supplier', ['type' => 'refund', 'credit' => 120]);
        Shortfalls::respond($c->fresh(), User::find($r->customer_id), 'customer', 'accept');

        $ref = Payment::where('rental_request_id', $r->id)->where('type', 'refund')->firstOrFail();
        $this->assertSame('verified', $ref->status);
        $this->assertSame('practice', $ref->gateway);
        $this->assertEquals(120, $ref->amount);
        $after = Payouts::breakdown($r->fresh()->load('payments'));
        $this->assertEqualsWithDelta($before['income'] - 120, $after['income'], 0.001);
        $this->assertEqualsWithDelta($before['net'] - 120 * (1 - $after['pct'] / 100), $after['net'], 0.01);
        $this->assertStringContainsString('Refunded', implode(' ', $c->fresh()->resolution['changes']));
    }

    public function test_xendit_refund_succeeded_pending_and_refused(): void
    {
        config(['services.xendit.secret_key' => 'xnd_development_abc', 'services.xendit.callback_token' => 'cb_tok', 'services.paymongo.fake' => false]);
        $calls = [];
        $reply = ['status' => 200, 'body' => ['id' => 'rfd_1', 'status' => 'SUCCEEDED']];
        Http::fake(function (HttpRequest $req) use (&$calls, &$reply) {
            $calls[] = [$req->method(), $req->url(), $req->data()];

            return Http::response($reply['body'], $reply['status']);
        });
        $r = $this->paidOnline('xendit', 'inv_77');

        // succeeded at once
        $a = $this->pendingRefund($r, 100);
        $this->assertSame('sent', Refunds::issue($a));
        $this->assertSame('verified', $a->fresh()->status);
        $this->assertSame(['POST', 'inv_77', 100.0, $a->code], [$calls[0][0], $calls[0][2]['invoice_id'], (float) $calls[0][2]['amount'], $calls[0][2]['reference_id']]);
        $this->assertStringEndsWith('/refunds', $calls[0][1]);

        // pending → the "refund.succeeded" callback finishes it
        $reply = ['status' => 200, 'body' => ['id' => 'rfd_2', 'status' => 'PENDING']];
        $b = $this->pendingRefund($r, 50);
        $this->assertSame('awaiting', Refunds::issue($b));
        $this->assertSame('awaiting', $b->fresh()->status);
        $this->postJson('/webhooks/xendit', ['event' => 'refund.succeeded', 'data' => ['id' => 'rfd_2', 'status' => 'SUCCEEDED']], ['x-callback-token' => 'cb_tok'])
            ->assertOk()->assertJson(['result' => 'sent']);
        $this->assertSame('verified', $b->fresh()->status);

        // refused (e.g. key without the refund permission) → stays pending for the cashier with the reason
        $reply = ['status' => 403, 'body' => ['error_code' => 'REQUEST_FORBIDDEN_ERROR', 'message' => 'The API key is forbidden to perform this request']];
        $c = $this->pendingRefund($r, 30);
        $this->assertSame('manual', Refunds::issue($c));
        $this->assertSame('pending', $c->fresh()->status);
        $this->assertStringContainsString('forbidden', $c->fresh()->notes);
        $this->assertFalse(Xendit::canRefund());
        $reply = ['status' => 200, 'body' => ['data' => [], 'has_more' => false]];
        $this->assertTrue(Xendit::canRefund());

        // the paid amount and payout follow the verified refunds (100 + 50)
        $this->assertEqualsWithDelta(150, $r->fresh()->fin()['refunded'], 0.001);
    }

    public function test_bank_payments_are_left_for_the_cashier(): void
    {
        config(['services.xendit.secret_key' => 'xnd_development_abc']);
        Http::fake();
        $r = $this->paidOnline('xendit');
        Payment::where('rental_request_id', $r->id)->update(['method' => 'bank', 'gateway' => null]);
        $this->assertSame('manual', Refunds::issue($this->pendingRefund($r, 40)));
        Http::assertNothingSent();
    }
}
