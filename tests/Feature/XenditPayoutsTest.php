<?php

namespace Tests\Feature;

use App\Domain\Payouts;
use App\Domain\Settings;
use App\Domain\XenditPayouts;
use App\Models\Payout;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Supplier payouts sent through Xendit Money-out (fake API). */
class XenditPayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    /** Status the fake API reports for POST / GET payouts. */
    private string $status = 'ACCEPTED';

    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.xendit.secret_key' => 'xnd_development_abc', 'services.xendit.callback_token' => 'cb_tok']);
        Http::fake(function (HttpRequest $req) {
            if ($req->method() === 'POST' && str_ends_with($req->url(), '/v2/payouts')) {
                $this->posts++;

                return Http::response(['id' => 'disb-1', 'reference_id' => $req['reference_id'], 'status' => $this->status, 'amount' => $req['amount']]);
            }

            return Http::response(['id' => 'disb-1', 'status' => $this->status] + ($this->status === 'FAILED' ? ['failure_code' => 'INVALID_DESTINATION'] : []));
        });
    }

    private function admin(): static
    {
        return $this->actingAs(User::where('email', 'admin@partyrent.ph')->firstOrFail())->withSession(['mode' => 'admin']);
    }

    private function pending(string $method = 'gcash', ?string $bank = null): Payout
    {
        $p = Payout::where('status', 'pending')->with('supplier')->firstOrFail();
        $p->supplier->update(['payout_method' => $method, 'payout_bank' => $bank, 'payout_account_name' => 'Juan Dela Cruz', 'payout_account_no' => '0917 555 0101']);

        return $p->fresh('supplier');
    }

    public function test_admin_sends_a_payout_and_it_arrives(): void
    {
        $p = $this->pending();
        $this->admin()->get('/admin/payouts')->assertOk()->assertSee('Send via Xendit');

        $this->post("/admin/payouts/{$p->code}/send")->assertSessionHas('success', fn ($m) => str_contains($m, 'on its way'));
        Http::assertSent(fn ($q) => $q->method() === 'POST' && $q['channel_code'] === 'PH_GCASH' && $q['reference_id'] === $p->code
            && (float) $q['amount'] === (float) $p->net && $q['channel_properties']['account_number'] === '09175550101' && $q->hasHeader('Idempotency-key'));
        $p->refresh();
        $this->assertSame(['sending', 'xendit', 'disb-1'], [$p->status, $p->gateway, $p->gateway_ref]);

        // a second click doesn't send twice; the manual form refuses too
        $this->post("/admin/payouts/{$p->code}/send")->assertSessionHas('error');
        $this->post("/admin/payouts/{$p->code}/pay", ['method' => 'gcash', 'reference' => 'X1'])->assertSessionHas('error');
        $this->assertSame(1, $this->posts);
        $this->get('/admin/payouts')->assertSee('Sending via Xendit');

        // Xendit finishes; opening the page picks it up
        $this->status = 'SUCCEEDED';
        $this->get('/admin/payouts')->assertOk();
        $p->refresh();
        $this->assertSame('paid', $p->status);
        $this->assertNotNull($p->paid_at);
        $this->get('/admin/payouts?tab=paid')->assertSee('via Xendit');
    }

    public function test_failed_payout_goes_back_to_pending_with_the_reason(): void
    {
        $p = $this->pending('bank', 'BDO');
        $this->admin()->post("/admin/payouts/{$p->code}/send");
        Http::assertSent(fn ($q) => $q->method() === 'POST' && $q['channel_code'] === 'PH_BDO');

        $this->status = 'FAILED';
        $this->post("/admin/payouts/{$p->code}/check")->assertSessionHas('error', fn ($m) => str_contains($m, 'invalid destination'));
        $p->refresh();
        $this->assertSame(['pending', null], [$p->status, $p->gateway_ref]);
        $this->get('/admin/payouts')->assertSee('Last try failed');

        // can be sent again
        $this->status = 'SUCCEEDED';
        $this->post("/admin/payouts/{$p->code}/send")->assertSessionHas('success', fn ($m) => str_contains($m, 'sent to'));
        $this->assertSame('paid', $p->fresh()->status);
    }

    public function test_webhook_marks_the_payout_paid(): void
    {
        $p = $this->pending();
        $this->admin()->post("/admin/payouts/{$p->code}/send");
        $body = ['event' => 'payout.succeeded', 'data' => ['id' => 'disb-1', 'reference_id' => $p->code, 'status' => 'SUCCEEDED']];

        $this->postJson('/webhooks/xendit', $body, ['x-callback-token' => 'nope'])->assertStatus(401);
        $this->postJson('/webhooks/xendit', $body, ['x-callback-token' => 'cb_tok'])->assertJson(['result' => 'paid']);
        $this->assertSame('paid', $p->fresh()->status);
    }

    public function test_unknown_bank_is_not_sent(): void
    {
        $p = $this->pending('bank', 'Bank of Nowhere');
        $this->assertNull(XenditPayouts::channel($p->supplier));
        $this->admin()->post("/admin/payouts/{$p->code}/send")->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot send'));
        $this->assertSame(0, $this->posts);
    }

    public function test_automatic_payout_when_a_rental_is_released(): void
    {
        Settings::set('payoutAuto', true);
        $this->status = 'SUCCEEDED';
        $p = Payout::with('rentalRequest', 'supplier')->firstOrFail();
        $r = $p->rentalRequest;
        $p->supplier->update(['payout_method' => 'gcash', 'payout_bank' => null, 'payout_account_name' => 'Juan', 'payout_account_no' => '09175550101']);
        $p->delete(); // release it again, as if the rental was just completed

        $res = Payouts::release(RentalRequest::findOrFail($r->id));
        $this->assertTrue($res['ok']);
        $this->assertSame('paid', $res['payout']->fresh()->status);
        $this->assertSame(1, $this->posts);
    }
}
