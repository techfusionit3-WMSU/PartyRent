<?php

namespace Tests\Feature;

use App\Domain\Finance;
use App\Domain\Payouts;
use App\Models\AppNotification;
use App\Models\Payout;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Escrow (Shopee-style): PartyRent holds customer money and pays suppliers after the rental, minus commission. */
class PayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    public function test_shop_cashier_cannot_manage_payouts(): void
    {
        // v4: cashiers belong to one shop; payouts stay on the PartyRent owner's side
        $this->as('cashier@elegantoccasion.ph', 'cashier');
        foreach (['/cashier', '/cashier/verification', '/cashier/balances', '/cashier/reports'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/admin/payouts')->assertRedirect();
        $this->post('/admin/payouts/'.Payout::firstOrFail()->code.'/pay', ['method' => 'bank', 'reference' => 'X'])->assertRedirect();
        $this->assertSame(0, Payout::where('reference', 'X')->count());
    }

    public function test_shop_cashier_sees_only_their_shop(): void
    {
        $this->markTestSkipped('Cashier pages are scoped per shop in the cashier port (PROGRESS step 3).');
    }

    public function test_seeded_payouts_and_owner_pays_a_supplier(): void
    {
        // the seeded completed rentals were released; older ones are already paid
        $this->assertGreaterThan(0, Payout::count());
        $this->assertSame(RentalRequest::where('status', 'completed')->count(), Payout::count());
        $p = Payout::where('status', 'pending')->with('supplier')->firstOrFail();
        $this->assertEquals(round($p->income * 0.05, 2), $p->commission);
        $this->assertEquals(round($p->income - $p->commission + $p->compensation, 2), $p->net);

        $this->as('admin@partyrent.ph', 'admin')->get('/admin/payouts')->assertOk()->assertSee($p->code)->assertSee($p->supplier->name);
        foreach (['release', 'paid', 'suppliers'] as $tab) {
            $this->get('/admin/payouts?tab='.$tab)->assertOk();
        }
        $this->post("/admin/payouts/{$p->code}/pay", ['method' => 'gcash', 'reference' => ''])->assertSessionHasErrors('reference');
        // no payout account yet → refused, and the supplier is reminded
        $acct = $p->supplier->only(['payout_method', 'payout_bank', 'payout_account_name', 'payout_account_no']);
        $p->supplier->update(['payout_method' => null]);
        $this->get('/admin/payouts')->assertSee('Waiting for the supplier');
        $this->post("/admin/payouts/{$p->code}/pay", ['method' => 'gcash', 'reference' => 'GC-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'payout account'));
        $this->assertTrue(AppNotification::where('user_id', $p->supplier->owner_id)->where('title', 'Add your payout account')->exists());
        $p->supplier->update(array_merge($acct, ['payout_method' => $acct['payout_method'] ?? 'gcash', 'payout_account_name' => $acct['payout_account_name'] ?? 'Owner', 'payout_account_no' => $acct['payout_account_no'] ?? '0917']));
        $this->post("/admin/payouts/{$p->code}/pay", ['method' => 'gcash', 'reference' => 'GC-778899'])->assertSessionHas('success');
        $p->refresh();
        $this->assertTrue($p->isPaid());
        $this->assertSame('GC-778899', $p->reference);
        $this->post("/admin/payouts/{$p->code}/pay", ['method' => 'gcash', 'reference' => 'again'])->assertSessionHas('error');
        $this->assertTrue(AppNotification::where('user_id', $p->supplier->owner_id)->where('title', 'like', 'Payout sent%')->exists());

        // the cashier cannot refund money that was already released to the supplier
        $r = $p->rentalRequest;
        $this->actingAs($this->shopCashier($r->code))->withSession(['mode' => 'cashier'])->post('/cashier/record', ['request' => $r->code, 'type' => 'refund', 'method' => 'cash', 'amount' => 10, 'date' => today_iso(), 'reference' => 'OR-1'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already released'));

        // superadmin sees the page too
        $this->as('super@partyrent.ph', 'superadmin')->get('/admin/payouts')->assertOk();
    }

    public function test_supplier_sees_escrow_and_sets_a_payout_account(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $s = $juan->supplier;
        $active = RentalRequest::where('supplier_id', $s->id)->whereIn('status', Finance::ACTIVE)->with('payments')->get();
        $held = $active->sum(fn ($r) => Payouts::held($r));

        $res = $this->as('juan@demo.ph', 'supplier')->get('/supplier/payouts')->assertOk()->assertSee('Held by PartyRent')->assertSee('Payout account');
        if ($held > 0) {
            $res->assertSee(money_short($held));
        }
        $this->put('/supplier/payouts/account', ['payout_method' => 'bank', 'payout_account_name' => 'Juan', 'payout_account_no' => '1234'])->assertSessionHasErrors('payout_bank');
        $this->put('/supplier/payouts/account', ['payout_method' => 'bank', 'payout_bank' => 'BPI', 'payout_account_name' => 'Juan Dela Cruz', 'payout_account_no' => 'abc'])->assertSessionHasErrors('payout_account_no');
        $this->put('/supplier/payouts/account', ['payout_method' => 'gcash', 'payout_bank' => 'BPI', 'payout_account_name' => 'Juan Dela Cruz', 'payout_account_no' => '0917 123 4567'])->assertSessionHas('success');
        $s->refresh();
        $this->assertSame('gcash', $s->payout_method);
        $this->assertNull($s->payout_bank);
        $this->assertSame('GCash · Juan Dela Cruz · 0917 123 4567', $s->payoutAccountLabel());

    }

    public function test_cancelled_request_with_money_kept_is_released_by_an_admin(): void
    {
        $r = RentalRequest::whereIn('status', Finance::ACTIVE)->with('payments')->get()
            ->first(fn ($x) => $x->fin()['paidRental'] > 0 && $x->payments->where('status', 'pending')->isEmpty());
        $this->assertNotNull($r, 'needs a seeded active request with a verified payment');
        $r->update(['status' => 'cancelled']);
        $r->refresh()->load('payments');
        $b = Payouts::breakdown($r);
        $this->assertTrue(Payouts::awaitingRelease()->contains('id', $r->id));

        $this->as('admin@partyrent.ph', 'admin')->get('/admin/payouts?tab=release')->assertOk()->assertSee($r->code);
        $this->post("/admin/payouts/release/{$r->code}")->assertSessionHas('success');
        $p = Payout::where('rental_request_id', $r->id)->firstOrFail();
        $this->assertEquals($b['net'], $p->net);
        $this->post("/admin/payouts/release/{$r->code}")->assertSessionHas('error'); // only once
        $this->assertFalse(Payouts::awaitingRelease()->contains('id', $r->id));

        // an active rental cannot be released early
        $live = RentalRequest::whereIn('status', Finance::ACTIVE)->firstOrFail();
        $this->post("/admin/payouts/release/{$live->code}")->assertSessionHas('error');
    }
}
