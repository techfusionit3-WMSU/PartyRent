<?php

namespace Tests\Feature;

use App\Domain\Payouts;
use App\Models\AppNotification;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Suppliers accept a request only after the customer's first payment is verified by PartyRent. */
class DownPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    private function req(string $code): RentalRequest
    {
        return RentalRequest::where('code', $code)->firstOrFail();
    }

    public function test_upfront_amount_follows_the_payment_plan(): void
    {
        // PR-0003 full + COD at s1 (30% down), PR-0004 reservation at s1 (₱500 fee), PR-0005 down at s2 (50% + ₱300 fee not credited)
        $cod = $this->req('PR-0003');
        $this->assertSame('down', $cod->fin()['upfront']['type']);
        $this->assertEquals(round($cod->pricing['payable'] * 0.30, 2), $cod->fin()['upfront']['amount']);
        $this->assertEquals(['amount' => 500.0, 'type' => 'reservation', 'label' => 'Reservation fee'], $this->req('PR-0004')->fin()['upfront']);
        $down = $this->req('PR-0005');
        $this->assertSame('Down payment + reservation fee', $down->fin()['upfront']['label']);
        $this->assertEquals(round($down->pricing['downRequired'] + 300, 2), $down->fin()['upfront']['amount']);
        $this->assertNull($this->req('PR-0001')->fin()['upfront']); // only pending requests have one
    }

    public function test_supplier_waits_for_the_down_payment(): void
    {
        $r = $this->req('PR-0003'); // nothing paid
        $this->as('juan@demo.ph', 'supplier')->get('/supplier/requests')->assertSee('Awaiting down payment')->assertSee('Down payment received'); // PR-0004 is paid
        $this->get('/supplier/requests/PR-0003')->assertOk()->assertSee('Waiting for the customer')->assertSee('Nothing paid yet');
        $this->post('/supplier/requests/PR-0003/accept')->assertSessionHas('error', fn ($e) => str_contains($e, 'Nothing has been paid yet'));
        $this->assertSame('pending', $r->fresh()->status);

        // the customer is asked to pay; the deposit is not accepted yet
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertOk()->assertSee('to confirm your request')->assertSee('needed before the supplier can accept');
        $this->post('/rentals/PR-0003/payments', ['type' => 'deposit', 'amount' => 100, 'method' => 'cod'])->assertSessionHas('error');
        $amt = $r->fin()['upfront']['amount'];
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'cod', 'reference' => 'cash at office'])->assertSessionHas('success');

        // submitted but unverified: still blocked
        $this->as('juan@demo.ph', 'supplier')->post('/supplier/requests/PR-0003/accept')->assertSessionHas('error', fn ($e) => str_contains($e, 'waiting for verification'));

        // cashier verifies → supplier is told they can accept
        $p = Payment::where('rental_request_id', $r->id)->where('status', 'pending')->firstOrFail();
        $this->as('cashier@partyrent.ph', 'cashier')->post("/cashier/payments/{$p->code}/verify")->assertSessionHas('success');
        $owner = $r->supplier->owner_id;
        $this->assertTrue(AppNotification::where('user_id', $owner)->where('title', 'like', 'Down payment received%')->exists());
        $this->as('juan@demo.ph', 'supplier')->get('/supplier/requests/PR-0003')->assertSee('received');
    }

    public function test_paid_request_can_be_accepted_and_decline_triggers_a_refund(): void
    {
        // PR-0004: reservation fee already verified
        $this->as('juan@demo.ph', 'supplier')->post('/supplier/requests/PR-0004/accept')->assertSessionHas('success');
        $this->assertSame('accepted', $this->req('PR-0004')->status);

        // PR-0005: down payment submitted; verify it, then the supplier declines → full refund
        $r = $this->req('PR-0005');
        $p = Payment::where('rental_request_id', $r->id)->where('status', 'pending')->firstOrFail();
        $this->as('cashier@partyrent.ph', 'cashier')->post("/cashier/payments/{$p->code}/verify")->assertSessionHas('success');
        $owner = User::findOrFail($r->supplier->owner_id);
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->post('/supplier/requests/PR-0005/decline', ['reason' => 'Fully booked'])->assertSessionHas('success');
        $this->assertTrue(AppNotification::where('role', 'cashier')->where('title', 'Refund due')->where('body', 'like', '%PR-0005%')->exists());
        $this->assertTrue(AppNotification::where('user_id', $r->customer_id)->where('body', 'like', '%refund your%')->exists());

        $this->assertSame('Refund Due', $r->fresh()->fin()['status']);
        $this->as('cashier@partyrent.ph', 'cashier')->get('/cashier/record?request=PR-0005')->assertOk();
        $this->post('/cashier/record', ['request' => 'PR-0005', 'type' => 'down', 'method' => 'cash', 'amount' => 10, 'date' => today_iso(), 'reference' => 'X'])->assertSessionHas('error'); // only refunds now
        $this->post('/cashier/record', ['request' => 'PR-0005', 'type' => 'refund', 'method' => 'bank', 'amount' => $p->amount, 'date' => today_iso(), 'reference' => 'RF-PR5'])->assertSessionHas('success');
        $this->assertSame('Refunded', $r->fresh()->fin()['status']);
    }

    public function test_free_cancellation_of_a_paid_pending_request_is_never_paid_out(): void
    {
        // PR-0004's customer cancels while it is still pending: refunded in full, nothing to release
        $this->as('bea@demo.ph', 'customer')->post('/rentals/PR-0004/cancel', ['reason' => 'Changed venue'])->assertSessionHas('success');
        $r = $this->req('PR-0004');
        $this->assertSame('cancelled', $r->status);
        $this->assertTrue($r->cancel_free);
        $this->assertFalse(Payouts::awaitingRelease()->contains('id', $r->id));
        $this->as('admin@partyrent.ph', 'admin')->post('/admin/payouts/release/PR-0004')->assertSessionHas('error');
        $this->assertTrue(AppNotification::where('role', 'cashier')->where('title', 'Refund review')->exists());
    }
}
