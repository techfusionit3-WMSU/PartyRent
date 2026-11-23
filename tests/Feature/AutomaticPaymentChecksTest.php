<?php

namespace Tests\Feature;

use App\Domain\Finance;
use App\Domain\PaymentChecks;
use App\Domain\PayMongo;
use App\Models\EventPayment;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * System update 2026-11-23: payment checking is automatic.
 * Customer pays → gateway → signed webhook verified → payment Verified + order status → transaction recorded,
 * with an automatic check for checkouts whose webhook never arrived, and an admin page to watch it all.
 */
class AutomaticPaymentChecksTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accepted('PR-0003');
        config(['services.paymongo.secret_key' => '', 'services.paymongo.webhook_secret' => '', 'services.paymongo.fake' => true, 'services.xendit.secret_key' => '']);
        Http::preventStrayRequests();
    }

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    /** Customer checks out PR-0003's down payment online and lands on the (practice) gateway page. */
    private function startCheckout(): array
    {
        $r = RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $amt = Finance::suggestedNext($r)['amount'];
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);

        return [$r, Payment::where('gateway', 'paymongo')->latest('id')->firstOrFail(), $amt];
    }

    public function test_paying_sends_a_signed_webhook_that_verifies_the_order_and_records_the_transaction(): void
    {
        [$r, $p, $amt] = $this->startCheckout();
        $this->assertSame('awaiting', $p->status);
        $paidBefore = $r->fresh()->fin()['paidRental'] ?? null;

        $this->post('/practice-checkout/'.$p->gateway_ref, ['action' => 'pay', 'method' => 'gcash']);

        // the webhook settled it before the customer even came back
        $p->refresh();
        $this->assertSame('verified', $p->status);
        $this->assertNotNull($p->receipt_no);
        $this->assertNull($p->verified_by, 'no person verified it');
        $this->assertContains($r->fresh()->fin()['status'], ['Partially Paid', 'Fully Paid']);

        $tx = PaymentTransaction::where('payment_id', $p->id)->sole();
        $this->assertSame('webhook', $tx->source);
        $this->assertSame('paid', $tx->result);
        $this->assertTrue($tx->verified);
        $this->assertSame('GCash', $tx->channel);
        $this->assertEqualsWithDelta($amt, $tx->amount, 0.001);
        $this->assertSame('PR-0003', $tx->order_code);

        // coming back afterwards changes nothing and records nothing new
        $this->get(route('rentals.online.return', [$r, $p]))->assertSessionHas('success', fn ($m) => str_contains($m, 'verified'));
        $this->assertSame(1, PaymentTransaction::count());
    }

    public function test_a_webhook_with_a_bad_signature_is_refused_and_recorded(): void
    {
        [, $p] = $this->startCheckout();
        PayMongo::practiceUpdate($p->gateway_ref, ['status' => 'paid', 'method' => 'card', 'paidAmount' => $p->amount, 'payId' => 'pay_x']);
        $body = json_encode(['data' => ['attributes' => ['type' => 'checkout_session.payment.paid', 'data' => ['id' => $p->gateway_ref, 'attributes' => PayMongo::practiceAttributes(PayMongo::practiceSession($p->gateway_ref))]]]]);

        $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => 't=1,te=forged,li='], $body)->assertStatus(401);
        $this->assertSame('awaiting', $p->fresh()->status);
        $this->assertTrue(PaymentTransaction::where('result', 'rejected')->where('verified', false)->exists());

        // the genuine, signed notification goes through
        $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => PaymentChecks::practiceSignature($body)], $body)
            ->assertOk()->assertJson(['result' => 'verified']);
        $this->assertSame('verified', $p->fresh()->status);
    }

    public function test_the_automatic_check_settles_a_payment_whose_webhook_was_lost(): void
    {
        [$r, $p] = $this->startCheckout();
        // paid at the gateway, but neither the webhook nor the customer came back
        PayMongo::practiceUpdate($p->gateway_ref, ['status' => 'paid', 'method' => 'paymaya', 'paidAmount' => $p->amount, 'payId' => 'pay_lost']);

        $this->artisan('partyrent:check-payments')->expectsOutputToContain('1 open checkout(s) checked: 1 paid')->assertOk();
        $p->refresh();
        $this->assertSame('verified', $p->status);
        $this->assertNotNull($p->checked_at);
        $this->assertSame('auto_check', PaymentTransaction::where('payment_id', $p->id)->value('source'));
        $this->assertNotNull(PaymentChecks::lastRun());
    }

    public function test_unpaid_checkouts_are_closed_after_a_day(): void
    {
        [, $p] = $this->startCheckout();
        PaymentChecks::run();
        $this->assertSame('awaiting', $p->fresh()->status, 'still within the day');

        $p->update(['submitted_at' => now()->subHours(PaymentChecks::EXPIRE_HOURS + 1)]);
        $n = PaymentChecks::run();
        $this->assertSame(1, $n['expired']);
        $this->assertNull(Payment::find($p->id), 'nothing was paid, so the checkout is dropped');
        $this->assertTrue(PaymentTransaction::where('payment_code', $p->code)->where('result', 'expired')->exists());
    }

    public function test_refunds_in_progress_at_the_gateway_are_left_alone(): void
    {
        $r = RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $refund = Payment::create(['code' => 'TXN-99999', 'rental_request_id' => $r->id, 'type' => 'refund', 'method' => 'online', 'amount' => 100,
            'date' => today_iso(), 'status' => 'awaiting', 'gateway' => 'xendit', 'gateway_ref' => 'rfd_1', 'recorded_by' => 'cashier', 'submitted_at' => now()->subDays(3)]);

        $this->assertSame(0, PaymentChecks::run()['checked']);
        $this->assertSame('awaiting', $refund->fresh()->status, 'a refund is not a checkout and is never closed');
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_smaller_amount_is_verified_for_what_arrived_without_anyone_approving(): void
    {
        [, $p] = $this->startCheckout();
        $due = $p->amount;
        PayMongo::practiceUpdate($p->gateway_ref, ['status' => 'paid', 'method' => 'card', 'paidAmount' => $due - 50, 'payId' => 'pay_short']);
        PaymentChecks::run();
        $p->refresh();
        $this->assertSame('verified', $p->status);
        $this->assertEqualsWithDelta($due - 50, $p->amount, 0.001, 'only what arrived counts; the rest stays due');
        $this->assertNull($p->verified_by);
    }

    public function test_more_than_was_due_is_an_exception_not_paid(): void
    {
        [, $p] = $this->startCheckout();
        PayMongo::practiceUpdate($p->gateway_ref, ['status' => 'paid', 'method' => 'card', 'paidAmount' => $p->amount + 50, 'payId' => 'pay_over']);
        PaymentChecks::run();
        $this->assertSame('pending', $p->fresh()->status);
        $this->assertSame('review', PaymentTransaction::where('payment_id', $p->id)->value('result'));
        $this->as('cashier@partyrent.ph', 'cashier')->get('/cashier/verification')->assertSee('Payment exceptions')->assertSee($p->code);
    }

    public function test_the_same_webhook_event_is_processed_only_once(): void
    {
        [, $p] = $this->startCheckout();
        PayMongo::practiceUpdate($p->gateway_ref, ['status' => 'paid', 'method' => 'gcash', 'paidAmount' => $p->amount, 'payId' => 'pay_1']);
        $body = json_encode(['data' => ['id' => 'evt_same', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => ['id' => $p->gateway_ref, 'attributes' => PayMongo::practiceAttributes(PayMongo::practiceSession($p->gateway_ref))]]]]);
        $send = fn () => $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => PaymentChecks::practiceSignature($body)], $body);

        $send()->assertOk()->assertJson(['result' => 'verified']);
        $send()->assertOk()->assertJson(['result' => 'duplicate']);
        $this->assertSame(1, PaymentTransaction::where('event_id', 'paymongo:evt_same')->count());
        $this->assertSame(1, Payment::where('rental_request_id', $p->rental_request_id)->whereNotNull('receipt_no')->where('gateway', 'paymongo')->count());
    }

    public function test_customers_cannot_send_a_manual_transfer_while_online_payment_is_on(): void
    {
        $r = RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertDontSee('<option value="bank"', false);
        $res = \App\Domain\Rentals::submitPayment($r, ['type' => 'down', 'amount' => 100, 'method' => 'bank', 'reference' => 'BDO-1'], 'customer');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Pay online', $res['error']);
        $this->assertSame(0, Payment::where('rental_request_id', $r->id)->where('method', 'bank')->where('status', 'pending')->where('reference', 'BDO-1')->count());
    }

    public function test_admin_sees_the_monitor_and_can_check_now(): void
    {
        [, $p] = $this->startCheckout();
        $this->as('admin@partyrent.ph', 'admin')->get('/admin/online-payments')->assertOk()
            ->assertSee('Payment checking is automatic')->assertSee('Practice mode')->assertSee($p->code)->assertSee('How a payment moves');
        $this->as('admin@partyrent.ph', 'admin')->get('/admin')->assertSee('Online Payments');

        PayMongo::practiceUpdate($p->gateway_ref, ['status' => 'paid', 'method' => 'gcash', 'paidAmount' => $p->amount, 'payId' => 'pay_now']);
        $this->as('admin@partyrent.ph', 'admin')->post('/admin/online-payments/check')->assertSessionHas('success', fn ($m) => str_contains($m, '1 paid and verified'));
        $this->assertSame('verified', $p->fresh()->status);
        $this->assertSame('manual_check', PaymentTransaction::where('payment_id', $p->id)->value('source'));
        $this->as('admin@partyrent.ph', 'admin')->get('/admin/online-payments?result=paid')->assertOk()->assertSee($p->code)->assertSee('Paid · verified');

        // superadmin too; customers and shops cannot open it; an admin without transactions.view cannot either
        $this->as('super@partyrent.ph', 'superadmin')->get('/admin/online-payments')->assertOk();
        $this->as('jose@demo.ph', 'customer')->get('/admin/online-payments')->assertRedirect(); // role middleware sends them home
        $this->as('jose@demo.ph', 'customer')->post('/admin/online-payments/check')->assertRedirect();
        $this->assertSame(1, PaymentTransaction::count());
        $limited = User::where('email', 'paolo.admin@partyrent.ph')->firstOrFail();
        $limited->update(['admin_perms' => ['customers.view']]);
        $this->actingAs($limited)->withSession(['mode' => 'admin'])->get('/admin/online-payments')->assertForbidden();
    }

    public function test_event_installment_paid_on_the_practice_gateway_is_verified_through_a_signed_notification(): void
    {
        $p = EventPayment::where('status', 'due')->whereHas('booking', fn ($q) => $q->where('status', 'confirmed'))->orderBy('id')->firstOrFail();
        $b = $p->booking;
        $this->as('maria@demo.ph', 'customer')->post(route('events.online', [$b, $p]))->assertRedirect(route('events.online.practice', [$b, $p]));
        $this->post(route('events.online.practice.pay', [$b, $p]), ['result' => 'pay'])->assertSessionHas('success');

        $p->refresh();
        $this->assertSame('paid', $p->status);
        $this->assertSame('online', $p->method);
        $tx = PaymentTransaction::where('event_payment_id', $p->id)->sole();
        $this->assertSame(['webhook', 'paid', 'event', true], [$tx->source, $tx->result, $tx->kind, $tx->verified]);
    }
}
