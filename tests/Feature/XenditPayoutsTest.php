<?php

namespace Tests\Feature;

use App\Domain\Payouts;
use App\Domain\Settings;
use App\Domain\XenditPayouts;
use App\Models\Payout;
use App\Models\PayoutBatch;
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

    /** A payout ready to send (a shop's 15-day period just ended with ₱1,500 to pay). */
    private function pending(string $method = 'gcash', ?string $bank = null): PayoutBatch
    {
        $s = \App\Models\Supplier::where('status', 'approved')->firstOrFail();
        $p = PayoutBatch::create(['code' => Payouts::nextCode(), 'supplier_id' => $s->id, 'schedule' => 'half_month', 'period_start' => add_days(today_iso(), -15), 'period_end' => add_days(today_iso(), -1),
            'earned' => 1500, 'commission' => 75, 'net' => 1500, 'status' => 'pending']);
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

    public function test_automatic_payout_when_the_period_ends(): void
    {
        Settings::set('payoutAuto', true);
        $this->status = 'SUCCEEDED';
        foreach (\App\Models\Supplier::all() as $s) {
            $s->update(['payout_method' => 'gcash', 'payout_bank' => null, 'payout_account_name' => 'Owner', 'payout_account_no' => '09175550101']);
        }
        // a completed rental earned ₱2,000 for a shop that owes nothing
        $r = RentalRequest::whereNotIn('id', Payout::select('rental_request_id'))->whereNotIn('supplier_id', PayoutBatch::select('supplier_id'))->firstOrFail();
        Payout::create(['code' => 'EA-T1', 'supplier_id' => $r->supplier_id, 'rental_request_id' => $r->id, 'income' => 2105.26, 'commission_pct' => 5, 'commission' => 105.26,
            'compensation' => 0, 'earned' => 2000, 'cash_kept' => 0, 'net' => 2000, 'status' => 'pending', 'created_at' => now()->subDays(20)]);
        $made = Payouts::closeDue(add_days(today_iso(), 40));
        $this->assertGreaterThan(0, $made);
        $this->assertSame(2000.0, PayoutBatch::where('supplier_id', $r->supplier_id)->value('net'));
        $sent = PayoutBatch::where('status', 'paid')->where('gateway', 'xendit')->get();
        $this->assertSame($sent->count(), $this->posts);
        $this->assertGreaterThan(0, $sent->count());
        // the earnings inside are paid too
        $this->assertSame(0, Payout::whereIn('payout_batch_id', $sent->pluck('id'))->where('status', '!=', 'paid')->count());
    }
}
