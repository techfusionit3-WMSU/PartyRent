<?php

namespace Tests\Feature;

use App\Domain\Settings;
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

    /* ---------------- step 1: accounts, QR, duplicate references ---------------- */

    public function test_customer_sees_partyrent_accounts_not_the_supplier(): void
    {
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertOk()
            ->assertSee('Send your payment to PartyRent')->assertSee('0917 555 0142')->assertSee('0012 3456 7890')
            ->assertDontSee('using the account details they provided')
            ->assertDontSee('Pay online'); // PayMongo is off without a key
    }

    public function test_superadmin_sets_accounts_and_qr(): void
    {
        Storage::fake('local');
        $base = ['platformName' => 'PartyRent', 'supportEmail' => 'support@partyrent.ph', 'supportPhone' => '1', 'overdueGraceDays' => 0, 'maxRentalDays' => 30, 'commissionPct' => 5];
        $super = User::whereJsonContains('roles', 'superadmin')->firstOrFail();
        $this->actingAs($super)->withSession(['mode' => 'superadmin'])->post('/superadmin/settings', $base + [
            'payGcashNumber' => '0999 000 1111', 'payGcashName' => 'PR Inc', 'payBankAccountNo' => '', 'payQr' => self::png('qr.png'),
        ])->assertSessionHas('success');
        Settings::flush();
        $this->assertSame('0999 000 1111', Settings::get('payGcashNumber'));
        $this->assertSame([['label' => 'GCash', 'name' => 'PR Inc', 'number' => '0999 000 1111']], Settings::payAccounts()); // empty bank hidden
        $qr = Settings::get('payQrPath');
        Storage::disk('local')->assertExists($qr);

        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertSee('0999 000 1111')->assertSee(route('payments.qr'));
        $this->get('/pay-qr')->assertOk();

        $this->actingAs($super)->withSession(['mode' => 'superadmin'])->post('/superadmin/settings', $base + ['payGcashNumber' => '0999 000 1111', 'payGcashName' => 'PR Inc', 'removeQr' => 1]);
        Settings::flush();
        $this->assertSame('', Settings::get('payQrPath'));
        Storage::disk('local')->assertMissing($qr);
    }

    public function test_a_reference_number_can_only_be_used_once(): void
    {
        $r = $this->req('PR-0003');
        $amt = $r->fin()['upfront']['amount'];
        $this->as('jose@demo.ph', 'customer');
        // BDO-884120 is verified on TXN-10001; spacing and case don't matter
        foreach (['BDO-884120', 'bdo 884120'] as $ref) {
            $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'bank', 'reference' => $ref, 'proof' => $this->proof()])
                ->assertSessionHas('error', fn ($e) => str_contains($e, 'already used'));
        }
        // the rejected UB-22O1A9 can be reused (it never counted)
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'bank', 'reference' => 'UB-22O1A9', 'proof' => $this->proof()])->assertSessionHas('success');
        // …but now it's pending, so a second submission is refused
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => 1, 'method' => 'bank', 'reference' => 'UB22O1A9', 'proof' => $this->proof()])->assertSessionHas('error');

        // the cashier's own bank records are checked too; refunds may reuse a reference
        $this->as('cashier@partyrent.ph', 'cashier')
            ->post('/cashier/record', ['request' => 'PR-0001', 'type' => 'installment', 'method' => 'bank', 'amount' => 10, 'date' => today_iso(), 'reference' => 'BDO-884377'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already used'));
    }

    public function test_cashier_cannot_verify_a_reused_reference(): void
    {
        $r = $this->req('PR-0001');
        // slipped in before the check existed (or two customers raced): same reference as verified TXN-10002
        $p = Payment::create(['code' => 'TXN-99001', 'rental_request_id' => $r->id, 'type' => 'installment', 'method' => 'bank', 'amount' => 10,
            'date' => today_iso(), 'reference' => 'bdo884377', 'status' => 'pending', 'recorded_by' => 'customer', 'submitted_at' => now()]);
        $this->assertSame('BDO884377', $p->reference_key);

        $this->as('cashier@partyrent.ph', 'cashier')->get('/cashier/verification')->assertOk()->assertSee('Reference already used')->assertSee('TXN-10002');
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
        $amt = $r->fin()['upfront']['amount'];

        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertSee('Pay online');
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online'])->assertRedirect('https://checkout.paymongo.com/cs_test_1');
        Http::assertSent(fn ($q) => $q->method() === 'POST' && $q['data']['attributes']['line_items'][0]['amount'] === (int) round($amt * 100)
            && $q['data']['attributes']['payment_method_types'] === ['gcash', 'card'] && $q->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_abc:')));

        $p = Payment::where('gateway', 'paymongo')->firstOrFail();
        $this->assertSame(['awaiting', 'online', 'cs_test_1'], [$p->status, $p->method, $p->gateway_ref]);
        $this->assertEquals(0, $r->fresh()->fin()['pendingRental']); // not counted until paid
        $this->as('cashier@partyrent.ph', 'cashier')->get('/cashier/verification')->assertDontSee($p->code); // not the cashier's job

        // back from checkout before paying
        $this->as('jose@demo.ph', 'customer')->get("/rentals/PR-0003/payments/{$p->code}/paymongo")->assertSessionHas('warn');
        $this->get('/rentals/PR-0003')->assertSee('Continue payment')->assertSee('Check status');

        // paid
        $this->paymongo($this->paid($amt));
        $this->get("/rentals/PR-0003/payments/{$p->code}/paymongo")->assertRedirect('/rentals/PR-0003')->assertSessionHas('success', fn ($m) => str_contains($m, 'verified'));
        $p->refresh();
        $this->assertSame(['verified', 'pay_test_9', 25.0], [$p->status, $p->reference, $p->gateway_fee]);
        $this->assertNotNull($p->receipt_no);
        $this->assertNull($p->verified_by);
        $this->assertTrue($r->fresh()->fin()['upfrontPaid']);

        // a second return (or the webhook) changes nothing
        $this->get("/rentals/PR-0003/payments/{$p->code}/paymongo")->assertSessionHas('success');
        $this->assertSame(1, Payment::where('receipt_no', $p->receipt_no)->count());
    }

    public function test_cancelled_checkout_is_discarded(): void
    {
        $this->paymongo();
        $amt = $this->req('PR-0003')->fin()['upfront']['amount'];
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $p = Payment::where('gateway', 'paymongo')->firstOrFail();
        $this->get("/rentals/PR-0003/payments/{$p->code}/paymongo?cancelled=1")->assertSessionHas('warn', fn ($m) => str_contains($m, 'Nothing was charged'));
        $this->assertNull($p->fresh());
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/checkout_sessions/cs_test_1/expire'));
    }

    public function test_starting_again_replaces_an_unpaid_checkout(): void
    {
        $this->paymongo();
        $amt = $this->req('PR-0003')->fin()['upfront']['amount'];
        $this->as('jose@demo.ph', 'customer');
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $this->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $this->assertSame(1, Payment::where('status', 'awaiting')->count());
    }

    public function test_webhook_requires_a_valid_signature_and_settles_the_payment(): void
    {
        $this->paymongo();
        $amt = $this->req('PR-0003')->fin()['upfront']['amount'];
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
        $amt = $r->fin()['upfront']['amount'];
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $p = Payment::where('gateway', 'paymongo')->firstOrFail();

        $this->paymongo($this->paid($amt - 5)); // PayMongo reports a different amount
        $this->get("/rentals/PR-0003/payments/{$p->code}/paymongo")->assertSessionHas('success', fn ($m) => str_contains($m, 'cashier'));
        $p->refresh();
        $this->assertSame('pending', $p->status);
        $this->assertStringContainsString('Needs review', $p->notes);
        $this->as('cashier@partyrent.ph', 'cashier')->get('/cashier/verification')->assertSee($p->code)->assertSee('Paid online through PayMongo');
    }

    public function test_online_payment_hidden_and_refused_when_not_configured(): void
    {
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => 100, 'method' => 'online'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not available'));
        $this->assertSame(0, Payment::where('gateway', 'paymongo')->count());
    }
}
