<?php

namespace Tests\Feature;

use App\Models\PayAccount;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** PartyRent's own collection accounts + QR, the duplicate reference check, and PayMongo online payments. */
class PaymentChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accepted('PR-0003'); // v4: payments only after accept
    }

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    private function req(string $code): RentalRequest
    {
        return RentalRequest::where('code', $code)->firstOrFail();
    }

    private function proof(): UploadedFile
    {
        return self::png('receipt.png');
    }

    private static function png(string $name): UploadedFile
    {
        // a 1×1 PNG (GD isn't installed, so ->image() can't be used)
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    /* ---------------- step 1: accounts, QR, monthly limits, duplicate references ---------------- */

    private function acc(string $label): PayAccount
    {
        return PayAccount::where('label', $label)->firstOrFail();
    }

    private function super(): static
    {
        return $this->actingAs(User::whereJsonContains('roles', 'superadmin')->firstOrFail())->withSession(['mode' => 'superadmin']);
    }

    public function test_customer_sees_active_partyrent_accounts_not_the_supplier(): void
    {
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertOk()
            ->assertSee('Send your payment to PartyRent')->assertSee('0917 555 0142')->assertSee('0012 3456 7890')
            ->assertDontSee('0918 555 0199') // Maya is switched off
            ->assertDontSee('using the account details they provided')
            ->assertDontSee('Pay online'); // PayMongo is off without a key
    }

    public function test_superadmin_manages_accounts(): void
    {
        Storage::fake('local');
        $this->super()->get('/superadmin/pay-accounts')->assertOk()->assertSee('GCash')->assertSee('Limit')->assertSee('Off');
        $this->post('/superadmin/pay-accounts', ['type' => 'bank', 'label' => 'BPI QR Ph', 'account_no' => '', 'qr' => self::png('qr.png')])->assertSessionHas('success');
        $bpi = $this->acc('BPI QR Ph');
        Storage::disk('local')->assertExists($bpi->qr_path);
        $this->post('/superadmin/pay-accounts', ['type' => 'bank', 'label' => 'Nothing'])->assertSessionHas('error'); // needs a number or a QR

        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertSee('BPI QR Ph')->assertSee(route('payments.qr', $bpi));
        $this->get(route('payments.qr', $bpi))->assertOk();

        // edit: add a number, drop the QR
        $old = $bpi->qr_path;
        $this->super()->post("/superadmin/pay-accounts/{$bpi->id}", ['type' => 'bank', 'label' => 'BPI', 'account_no' => '1234', 'monthly_cap' => '', 'remove_qr' => 1])->assertSessionHas('success');
        $bpi->refresh();
        $this->assertSame(['BPI', '1234', null, null], [$bpi->label, $bpi->account_no, $bpi->qr_path, $bpi->monthly_cap]);
        Storage::disk('local')->assertMissing($old);

        // off → hidden from customers; removable while unused
        $this->post("/superadmin/pay-accounts/{$bpi->id}/toggle");
        $this->assertFalse($bpi->fresh()->active);
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertDontSee('>BPI<', false);
        $this->super()->post("/superadmin/pay-accounts/{$bpi->id}/delete")->assertSessionHas('success');
        $this->assertNull($bpi->fresh());

        // an account with payments can only be turned off
        $gcash = $this->acc('GCash');
        Payment::where('method', 'bank')->first()->update(['pay_account_id' => $gcash->id]);
        $this->post("/superadmin/pay-accounts/{$gcash->id}/delete")->assertSessionHas('error');

        // other roles can't manage accounts
        $this->actingAs($this->shopCashier('PR-0003'))->withSession(['mode' => 'cashier'])->get('/superadmin/pay-accounts')->assertRedirect(); // role middleware sends them home
    }

    public function test_monthly_limit_moves_customers_to_another_account(): void
    {
        $r = $this->req('PR-0003');
        $amt = \App\Domain\Finance::suggestedNext($r)['amount'] - 200; // leave room in the balance for the ₱100 below
        $gcash = $this->acc('GCash');
        $gcash->update(['monthly_cap' => $amt + 50]); // room for this payment and ₱50 more
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertSee('data-room="'.round($amt + 50, 2).'"', false); // the form hides it per amount

        $this->post('/rentals/PR-0003/payments', ['type' => 'full', 'amount' => $amt, 'method' => 'bank', 'reference' => 'GC-1', 'proof' => $this->proof()])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Choose which PartyRent account'));
        $this->post('/rentals/PR-0003/payments', ['type' => 'full', 'amount' => $amt, 'method' => 'bank', 'pay_account' => $this->acc('Maya')->id, 'reference' => 'GC-1', 'proof' => $this->proof()])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'no longer accepting'));
        $this->post('/rentals/PR-0003/payments', ['type' => 'full', 'amount' => $amt, 'method' => 'bank', 'pay_account' => $gcash->id, 'reference' => 'GC-1', 'proof' => $this->proof()])
            ->assertSessionHas('success');
        $p = Payment::where('reference', 'GC-1')->firstOrFail();
        $this->assertSame($gcash->id, $p->pay_account_id);
        $this->assertEquals($amt, $gcash->receivedThisMonth()); // pending counts against the limit
        $this->assertEquals(50, $gcash->room());

        // ₱100 more won't fit GCash this month; BDO has no limit
        $this->post('/rentals/PR-0003/payments', ['type' => 'full', 'amount' => 100, 'method' => 'bank', 'pay_account' => $gcash->id, 'reference' => 'GC-2', 'proof' => $this->proof()])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot take'));
        $this->post('/rentals/PR-0003/payments', ['type' => 'full', 'amount' => 100, 'method' => 'bank', 'pay_account' => $this->acc('BDO')->id, 'reference' => 'BDO-2', 'proof' => $this->proof()])
            ->assertSessionHas('success');

        // the cashier sees where to look
        $this->actingAs($this->shopCashier('PR-0003'))->withSession(['mode' => 'cashier'])->get('/cashier/verification')->assertSee('Paid to')->assertSee('GCash 0917 555 0142');
        $this->super()->get('/superadmin/pay-accounts')->assertSee(money(50).' left');

        // a rejected payment frees the room again
        $this->actingAs($this->shopCashier('PR-0003'))->withSession(['mode' => 'cashier'])->post("/cashier/payments/{$p->code}/reject", ['reason' => 'not found']);
        $this->assertEquals($amt + 50, $gcash->room());
    }

    public function test_a_reference_number_can_only_be_used_once(): void
    {
        $r = $this->req('PR-0003');
        $amt = \App\Domain\Finance::suggestedNext($r)['amount'];
        $bdo = $this->acc('BDO')->id;
        $this->as('jose@demo.ph', 'customer');
        // BDO-884377 is verified on TXN-10002; spacing and case don't matter
        foreach (['BDO-884377', 'bdo 884377'] as $ref) {
            $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'bank', 'pay_account' => $bdo, 'reference' => $ref, 'proof' => $this->proof()])
                ->assertSessionHas('error', fn ($e) => str_contains($e, 'already used'));
        }
        // the rejected UB-22O1A9 can be reused (it never counted)
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'bank', 'pay_account' => $bdo, 'reference' => 'UB-22O1A9', 'proof' => $this->proof()])->assertSessionHas('success');
        // …but now it's pending, so a second submission is refused
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => 1, 'method' => 'bank', 'pay_account' => $bdo, 'reference' => 'UB22O1A9', 'proof' => $this->proof()])->assertSessionHas('error');

        // the cashier's own bank records are checked too, and can say which account received it
        $this->actingAs($this->shopCashier('PR-0003'))->withSession(['mode' => 'cashier'])
            ->post('/cashier/record', ['request' => 'PR-0001', 'type' => 'installment', 'method' => 'bank', 'amount' => 10, 'date' => today_iso(), 'reference' => 'BDO-884377'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already used'));
        $this->get('/cashier/record?request=PR-0001')->assertSee('Received into');
        $this->post('/cashier/record', ['request' => 'PR-0001', 'type' => 'installment', 'method' => 'bank', 'pay_account' => $bdo, 'amount' => 10, 'date' => today_iso(), 'reference' => 'BDO-NEW-1'])
            ->assertSessionHas('success');
        $this->assertSame($bdo, Payment::where('reference', 'BDO-NEW-1')->value('pay_account_id'));
    }

    public function test_cashier_cannot_verify_a_reused_reference(): void
    {
        $r = $this->req('PR-0001');
        // slipped in before the check existed (or two customers raced): same reference as verified TXN-10002
        $p = Payment::create(['code' => 'TXN-99001', 'rental_request_id' => $r->id, 'type' => 'installment', 'method' => 'bank', 'amount' => 10,
            'date' => today_iso(), 'reference' => 'bdo884377', 'status' => 'pending', 'recorded_by' => 'customer', 'submitted_at' => now()]);
        $this->assertSame('BDO884377', $p->reference_key);

        $this->actingAs($this->shopCashier('PR-0003'))->withSession(['mode' => 'cashier'])->get('/cashier/verification')->assertOk()->assertSee('Reference already used')->assertSee('TXN-10002');
        $this->post('/cashier/payments/TXN-99001/verify')->assertSessionHas('error', fn ($e) => str_contains($e, 'already verified'));
        $this->assertSame('pending', $p->fresh()->status);
    }

    /* ---------------- PayMongo ---------------- */

    /** What the fake PayMongo API returns for GET /checkout_sessions/{id}. */
    private array $session = [];

    private bool $faked = false;

    private function paymongo(array $sessionAttrs = []): void
    {
        $this->session = $sessionAttrs;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        config(['services.paymongo.secret_key' => 'sk_test_abc', 'services.paymongo.webhook_secret' => 'whsk_test', 'services.paymongo.methods' => ['gcash', 'card']]);
        Http::fake(function (HttpRequest $req) {
            if ($req->method() === 'POST' && str_ends_with($req->url(), '/checkout_sessions')) {
                return Http::response(['data' => ['id' => 'cs_test_1', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_1', 'payments' => []]]]);
            }
            if (str_ends_with($req->url(), '/expire')) {
                return Http::response(['data' => ['id' => 'cs_test_1']]);
            }

            return Http::response(['data' => ['id' => 'cs_test_1', 'attributes' => $this->session]]);
        });
    }

    private function paid(float $amount, int $fee = 2500): array
    {
        return ['payments' => [['id' => 'pay_test_9', 'attributes' => ['status' => 'paid', 'amount' => (int) round($amount * 100), 'fee' => $fee, 'source' => ['type' => 'gcash']]]]];
    }

    public function test_online_payment_is_verified_automatically_on_return(): void
    {
        $this->paymongo();
        $r = $this->req('PR-0003');
        $amt = \App\Domain\Finance::suggestedNext($r)['amount'];

        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertSee('Pay online');
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online'])->assertRedirect('https://checkout.paymongo.com/cs_test_1');
        Http::assertSent(fn ($q) => $q->method() === 'POST' && $q['data']['attributes']['line_items'][0]['amount'] === (int) round($amt * 100)
            && $q['data']['attributes']['payment_method_types'] === ['gcash', 'card'] && $q->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_abc:')));

        $p = Payment::where('gateway', 'paymongo')->firstOrFail();
        $this->assertSame(['awaiting', 'online', 'cs_test_1'], [$p->status, $p->method, $p->gateway_ref]);
        $this->assertEquals(0, $r->fresh()->fin()['pendingRental']); // not counted until paid
        $this->actingAs($this->shopCashier('PR-0003'))->withSession(['mode' => 'cashier'])->get('/cashier/verification')->assertDontSee($p->code); // not the cashier's job

        // back from checkout before paying
        $this->as('jose@demo.ph', 'customer')->get("/rentals/PR-0003/payments/{$p->code}/online")->assertSessionHas('warn');
        $this->get('/rentals/PR-0003')->assertSee('Continue payment')->assertSee('Check status');

        // paid
        $this->paymongo($this->paid($amt));
        $this->get("/rentals/PR-0003/payments/{$p->code}/online")->assertRedirect('/rentals/PR-0003')->assertSessionHas('success', fn ($m) => str_contains($m, 'verified'));
        $p->refresh();
        $this->assertSame(['verified', 'pay_test_9', 25.0], [$p->status, $p->reference, $p->gateway_fee]);
        $this->assertNotNull($p->receipt_no);
        $this->assertNull($p->verified_by);
        $this->assertTrue($r->fresh()->fin()['reservationConfirmed']);

        // a second return (or the webhook) changes nothing
        $this->get("/rentals/PR-0003/payments/{$p->code}/online")->assertSessionHas('success');
        $this->assertSame(1, Payment::where('receipt_no', $p->receipt_no)->count());
    }

    public function test_cancelled_checkout_is_discarded(): void
    {
        $this->paymongo();
        $amt = \App\Domain\Finance::suggestedNext($this->req('PR-0003'))['amount'];
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $p = Payment::where('gateway', 'paymongo')->firstOrFail();
        $this->get("/rentals/PR-0003/payments/{$p->code}/online?cancelled=1")->assertSessionHas('warn', fn ($m) => str_contains($m, 'Nothing was charged'));
        $this->assertNull($p->fresh());
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/checkout_sessions/cs_test_1/expire'));
    }

    public function test_starting_again_replaces_an_unpaid_checkout(): void
    {
        $this->paymongo();
        $amt = \App\Domain\Finance::suggestedNext($this->req('PR-0003'))['amount'];
        $this->as('jose@demo.ph', 'customer');
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $this->assertSame(1, Payment::where('status', 'awaiting')->count());
    }

    public function test_webhook_requires_a_valid_signature_and_settles_the_payment(): void
    {
        $this->paymongo();
        $amt = \App\Domain\Finance::suggestedNext($this->req('PR-0003'))['amount'];
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $p = Payment::where('gateway', 'paymongo')->firstOrFail();

        $body = json_encode(['data' => ['id' => 'evt_1', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false,
            'data' => ['id' => 'cs_test_1', 'type' => 'checkout_session', 'attributes' => $this->paid($amt)]]]]);
        $t = (string) time();
        $send = fn (string $sig) => $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $sig], $body);

        $send("t=$t,te=bad,li=")->assertStatus(401);
        $this->assertSame('awaiting', $p->fresh()->status);

        $send("t=$t,te=".hash_hmac('sha256', $t.'.'.$body, 'whsk_test').',li=')->assertOk()->assertJson(['result' => 'verified']);
        $this->assertSame('verified', $p->fresh()->status);
    }

    public function test_paid_amount_that_no_longer_fits_goes_to_the_cashier(): void
    {
        $this->paymongo();
        $r = $this->req('PR-0003');
        $amt = \App\Domain\Finance::suggestedNext($r)['amount'];
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $p = Payment::where('gateway', 'paymongo')->firstOrFail();

        $this->paymongo($this->paid($amt - 5)); // PayMongo reports a different amount
        $this->get("/rentals/PR-0003/payments/{$p->code}/online")->assertSessionHas('success', fn ($m) => str_contains($m, 'cashier'));
        $p->refresh();
        $this->assertSame('pending', $p->status);
        $this->assertStringContainsString('Needs review', $p->notes);
        $this->actingAs($this->shopCashier('PR-0003'))->withSession(['mode' => 'cashier'])->get('/cashier/verification')->assertSee($p->code)->assertSee('Paid online through PayMongo');
    }

    public function test_online_payment_hidden_and_refused_when_not_configured(): void
    {
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => 100, 'method' => 'online'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not available'));
        $this->assertSame(0, Payment::where('gateway', 'paymongo')->count());
    }
}
