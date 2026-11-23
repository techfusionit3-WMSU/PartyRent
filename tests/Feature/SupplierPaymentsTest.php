<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payment Monitoring: transfers go to PartyRent's account, so PartyRent (not the shop) verifies them;
 * a shop confirms only cash it received. Other shops cannot touch either.
 */
class SupplierPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_shop_sees_transfers_but_partyrent_verifies_them(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        // an old-style transfer (sent before automatic payments) still waiting for PartyRent
        $req = \App\Models\RentalRequest::where('supplier_id', $juan->supplier->id)->whereIn('status', \App\Domain\Finance::ACTIVE)->firstOrFail();
        $pending = Payment::create(['code' => 'TXN-OLD01', 'rental_request_id' => $req->id, 'type' => 'balance', 'method' => 'bank', 'amount' => 100,
            'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'BPI-OLD-1', 'status' => 'pending', 'recorded_by' => 'customer']);

        // another shop's owner cannot touch it
        $ana = User::where('email', 'ana@demo.ph')->firstOrFail();
        $this->actingAs($ana)->withSession(['mode' => 'supplier'])->post(route('supplier.payments.verify', $pending))->assertNotFound();

        // the owner sees it waiting, but cannot verify a transfer into PartyRent's account
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.payments'))->assertOk()->assertSee('Waiting for confirmation')->assertSee($pending->code)->assertSee('PartyRent is checking');
        $this->get(route('supplier.payments', ['tab' => 'balances']))->assertOk()->assertSee('Next deadline');
        $this->post(route('supplier.payments.verify', $pending))->assertSessionHas('error', fn ($e) => str_contains($e, 'PartyRent checks'));
        $this->assertSame('pending', $pending->fresh()->status);

        // PartyRent's admin only sees it (records), never verifies or records money
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->get('/cashier/verification')->assertOk()->assertSee($pending->code)->assertSee('View only')->assertDontSee('action="'.route('cashier.verify', $pending).'"', false);
        $this->get('/cashier/history')->assertOk();
        $this->post("/cashier/payments/{$pending->code}/verify")->assertForbidden();
        $this->post("/cashier/payments/{$pending->code}/reject", ['reason' => 'x'])->assertForbidden();
        $this->get('/cashier/record')->assertForbidden();
        $this->get('/cashier/balances')->assertForbidden();
        $this->assertSame('pending', $pending->fresh()->status);

        // PartyRent's cashier verifies it
        $cashier = User::where('email', 'cashier@partyrent.ph')->firstOrFail();
        $this->actingAs($cashier)->withSession(['mode' => 'cashier'])->get('/cashier/verification')->assertOk()->assertSee($pending->code);
        $this->post("/cashier/payments/{$pending->code}/verify")->assertSessionHas('success');
        $pending->refresh();
        $this->assertSame('verified', $pending->status);
        $this->assertNotNull($pending->receipt_no);
        $this->assertSame($cashier->id, $pending->verified_by);
    }

    public function test_shop_owner_confirms_cash_it_received(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $r = $juan->supplier->rentalRequests()->whereIn('status', \App\Domain\Finance::ACTIVE)->with('payments')->get()->first(fn ($x) => $x->fin()['balance'] >= 100);
        $cash = Payment::create(['code' => 'PAY-CASH1', 'rental_request_id' => $r->id, 'type' => 'balance', 'method' => 'cash', 'amount' => 100,
            'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'IN-PERSON', 'status' => 'pending']);

        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $this->post(route('supplier.payments.reject', $cash), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('supplier.payments.verify', $cash))->assertSessionHas('success');
        $this->assertSame('verified', $cash->fresh()->status);
        $this->assertSame($juan->id, $cash->fresh()->verified_by);
    }

    public function test_shop_records_cash_only_and_customer_can_dispute_it(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $r = $juan->supplier->rentalRequests()->whereIn('status', \App\Domain\Finance::ACTIVE)->with('payments')->get()->first(fn ($x) => $x->fin()['balance'] >= 100);
        $cashier = $this->shopCashier($r->code);
        $this->actingAs($cashier)->withSession(['mode' => 'supplier']);
        $this->get('/cashier/record?request='.$r->code)->assertOk()->assertSee('Record cash');
        // a bank transfer is PartyRent's to record
        $this->post('/cashier/record', ['request' => $r->code, 'type' => 'balance', 'method' => 'bank', 'amount' => 50, 'date' => today_iso(), 'reference' => 'BDO-9'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'cash only'));
        $this->post('/cashier/record', ['request' => $r->code, 'type' => 'balance', 'method' => 'cash', 'amount' => 50, 'date' => today_iso()])->assertSessionHas('success');
        $p = Payment::where('rental_request_id', $r->id)->where('method', 'cash')->latest('id')->firstOrFail();

        // the customer says they never paid it → a complaint for PartyRent support
        $customer = $r->customer;
        $this->actingAs($customer)->withSession(['mode' => 'customer']);
        $this->get('/rentals/'.$r->code)->assertOk()->assertSee('Not right?');
        $this->post(route('rentals.dispute', [$r, $p]), ['why' => 'no'])->assertSessionHasErrors('why');
        $this->post(route('rentals.dispute', [$r, $p]), ['why' => 'I did not pay any cash.'])->assertSessionHas('success');
        $this->assertNotNull($p->fresh()->disputed_at);
        $this->assertDatabaseHas('complaints', ['rental_request_id' => $r->id, 'reporter_id' => $customer->id, 'priority' => 'high']);
        $this->post(route('rentals.dispute', [$r, $p]), ['why' => 'Again please.'])->assertSessionHas('error');
        // nobody else can dispute it
        $this->actingAs($juan)->post(route('rentals.dispute', [$r, $p]), ['why' => 'Not mine at all'])->assertNotFound();
    }
}
