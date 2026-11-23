<?php

namespace Tests\Feature;

use App\Domain\OnlinePay;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Pay online" through Xendit Invoices (fake API). */
class XenditTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    /** What the fake API returns for GET /v2/invoices/{id}. */
    private array $invoice = ['status' => 'PENDING'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->accepted('PR-0003'); // v4: payments only after accept
        // practice mode is on in the local .env; a Xendit key must take over from it
        config(['services.paymongo.fake' => true, 'services.paymongo.secret_key' => '',
            'services.xendit.secret_key' => 'xnd_development_abc', 'services.xendit.callback_token' => 'cb_tok', 'services.xendit.methods' => ['GCASH', 'PAYMAYA']]);
        Http::fake(function (HttpRequest $req) {
            if ($req->method() === 'POST' && str_ends_with($req->url(), '/v2/invoices')) {
                return Http::response(['id' => 'inv_1', 'external_id' => $req['external_id'], 'status' => 'PENDING', 'invoice_url' => 'https://checkout-staging.xendit.co/web/inv_1']);
            }
            if (str_ends_with($req->url(), '/expire!')) {
                return Http::response(['id' => 'inv_1', 'status' => 'EXPIRED']);
            }

            return Http::response(['id' => 'inv_1'] + $this->invoice);
        });
    }

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    private function startPayment(): array
    {
        $r = RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $amt = \App\Domain\Finance::suggestedNext($r)['amount'];
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online'])
            ->assertRedirect('https://checkout-staging.xendit.co/web/inv_1');

        return [$r, Payment::where('gateway', 'xendit')->firstOrFail(), $amt];
    }

    private function paid(string $code, float $amount): array
    {
        return ['status' => 'PAID', 'external_id' => $code, 'paid_amount' => $amount, 'payment_channel' => 'GCASH', 'payment_method' => 'EWALLET', 'payment_id' => 'ewc_9', 'fees_paid_amount' => 23.5];
    }

    public function test_xendit_key_replaces_practice_mode(): void
    {
        $this->assertSame('xendit', OnlinePay::gateway());
        $this->assertFalse(OnlinePay::practice());
        $this->as('jose@demo.ph', 'customer')->get('/rentals/PR-0003')->assertSee('Pay online')->assertSee('Xendit')->assertDontSee('Practice mode');
        $this->actingAs(User::where('email', 'super@partyrent.ph')->firstOrFail())->withSession(['mode' => User::where('email', 'super@partyrent.ph')->first()->defaultMode()])->get('/superadmin/settings')->assertOk()->assertSee('Xendit')->assertSee('Test mode');
    }

    public function test_online_payment_is_verified_automatically_on_return(): void
    {
        [$r, $p, $amt] = $this->startPayment();
        Http::assertSent(fn ($q) => $q->method() === 'POST' && $q['external_id'] === $p->code && (float) $q['amount'] === (float) $amt && $q['currency'] === 'PHP'
            && $q['payment_methods'] === ['GCASH', 'PAYMAYA'] && str_contains($q['success_redirect_url'], "/payments/{$p->code}/online")
            && $q->hasHeader('Authorization', 'Basic '.base64_encode('xnd_development_abc:')));
        $this->assertSame(['awaiting', 'online', 'inv_1'], [$p->status, $p->method, $p->gateway_ref]);

        // back before paying
        $this->get("/rentals/PR-0003/payments/{$p->code}/online")->assertSessionHas('warn');
        $this->assertSame('awaiting', $p->fresh()->status);

        $this->invoice = $this->paid($p->code, $amt);
        $this->get("/rentals/PR-0003/payments/{$p->code}/online")->assertRedirect('/rentals/PR-0003')->assertSessionHas('success', fn ($m) => str_contains($m, 'verified'));
        $p->refresh();
        $this->assertSame(['verified', 'ewc_9', 23.5], [$p->status, $p->reference, $p->gateway_fee]);
        $this->assertStringContainsString('via GCash', $p->notes);
        $this->assertNotNull($p->receipt_no);
        $this->assertTrue($r->fresh()->fin()['reservationConfirmed']);
    }

    public function test_cancelled_invoice_is_expired_and_discarded(): void
    {
        [, $p] = $this->startPayment();
        $this->get("/rentals/PR-0003/payments/{$p->code}/online?cancelled=1")->assertSessionHas('warn', fn ($m) => str_contains($m, 'Nothing was charged'));
        $this->assertNull($p->fresh());
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/invoices/inv_1/expire!'));
    }

    public function test_callback_requires_the_token_and_settles_the_payment(): void
    {
        [, $p, $amt] = $this->startPayment();
        $body = ['id' => 'inv_1'] + $this->paid($p->code, $amt);

        $this->postJson('/webhooks/xendit', $body, ['x-callback-token' => 'wrong'])->assertStatus(401);
        $this->assertSame('awaiting', $p->fresh()->status);

        $this->postJson('/webhooks/xendit', $body, ['x-callback-token' => 'cb_tok'])->assertOk()->assertJson(['result' => 'verified']);
        $this->assertSame('verified', $p->fresh()->status);
        // Xendit retries callbacks; a repeat changes nothing
        $this->postJson('/webhooks/xendit', $body, ['x-callback-token' => 'cb_tok'])->assertJson(['result' => 'duplicate']);
    }

    public function test_different_amount_goes_to_the_cashier(): void
    {
        [, $p, $amt] = $this->startPayment();
        $this->invoice = $this->paid($p->code, $amt + 5); // more than was due: an exception
        $this->get("/rentals/PR-0003/payments/{$p->code}/online")->assertSessionHas('success', fn ($m) => str_contains($m, 'cashier'));
        $this->assertSame('pending', $p->fresh()->status);
        $this->actingAs($this->shopCashier('PR-0003'))->withSession(['mode' => 'cashier'])->get('/cashier/verification')->assertSee($p->code)->assertSee('Paid online through Xendit');
    }

    public function test_refused_invoice_shows_the_error_and_leaves_no_payment(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['error_code' => 'API_VALIDATION_ERROR', 'message' => 'amount is too low'], 400)]);
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => 100, 'method' => 'online'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'amount is too low'));
        $this->assertSame(0, Payment::where('gateway', 'xendit')->count());
    }
}
