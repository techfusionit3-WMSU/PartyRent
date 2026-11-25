<?php

namespace Tests\Feature;

use App\Domain\Finance;
use App\Domain\Payouts;
use App\Models\AppNotification;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Escrow and scheduled payouts: customers pay PartyRent; a completed rental's earnings wait for the shop's next
 * payout (every 15 days or monthly); cash the shop collected is taken off; a negative balance is carried.
 */
class PayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    /** A shop with no payout history, its account set. */
    private function freshShop(): Supplier
    {
        $s = Supplier::where('status', 'approved')->whereNotIn('id', Payout::select('supplier_id'))->whereNotIn('id', PayoutBatch::select('supplier_id'))->firstOrFail();
        $s->update(['payout_method' => 'gcash', 'payout_bank' => null, 'payout_account_name' => 'Owner', 'payout_account_no' => '09175550101']);

        return $s->fresh();
    }

    /** Earnings of one rental of $s, added on $day. */
    private function earning(Supplier $s, float $earned, float $cash, string $day): Payout
    {
        static $n = 0;
        $r = RentalRequest::where('status', 'completed')->firstOrFail()->replicate();
        $r->fill(['code' => 'PR-T'.(++$n), 'supplier_id' => $s->id, 'start_date' => $day, 'end_date' => $day, 'event_date' => $day, 'completed_at' => $day])->save();

        return Payout::create(['code' => 'EA-T'.$n, 'supplier_id' => $s->id, 'rental_request_id' => $r->id, 'income' => $earned, 'commission_pct' => 5, 'commission' => r2($earned * 0.05),
            'compensation' => 0, 'earned' => $earned, 'cash_kept' => $cash, 'net' => r2($earned - $cash), 'status' => 'pending', 'created_at' => $day.' 18:00:00']);
    }

    public function test_periods_for_each_schedule(): void
    {
        $this->assertSame(['2026-11-01', '2026-11-15'], Payouts::period('2026-11-09', 'half_month'));
        $this->assertSame(['2026-11-16', '2026-11-30'], Payouts::period('2026-11-19', 'half_month'));
        $this->assertSame(['2026-11-01', '2026-11-30'], Payouts::period('2026-11-19', 'monthly'));
        $this->assertSame('2026-11-15', Payouts::lastClosedEnd('half_month', '2026-11-16'));
        $this->assertSame('2026-10-31', Payouts::lastClosedEnd('half_month', '2026-11-15'));
        $this->assertSame('2026-10-31', Payouts::lastClosedEnd('monthly', '2026-11-19'));
        $s = new Supplier(['payout_schedule' => 'monthly']);
        $this->assertSame('2026-12-01', Payouts::nextPayoutDate($s, '2026-11-19'));
        $s->payout_schedule = 'half_month';
        $this->assertSame('2026-11-16', Payouts::nextPayoutDate($s, '2026-11-09'));
    }

    public function test_one_payout_per_period_with_cash_taken_off(): void
    {
        $s = $this->freshShop();
        $this->earning($s, 5000, 0, '2026-11-03');
        $this->earning($s, 3000, 1000, '2026-11-10');
        $this->earning($s, 900, 0, '2026-11-17'); // next period

        $this->assertSame(0, Payouts::closeDue('2026-11-15', $s->id)); // the period is not over yet
        $this->assertSame(1, Payouts::closeDue('2026-11-16', $s->id));
        $b = PayoutBatch::where('supplier_id', $s->id)->firstOrFail();
        $this->assertSame(['2026-11-01', '2026-11-15', 'pending'], [$b->period_start->toDateString(), $b->period_end->toDateString(), $b->status]);
        $this->assertEquals([8000.0, 1000.0, 7000.0], [$b->earned, $b->cash_kept, $b->net]);
        $this->assertSame(2, $b->lines()->count());
        $this->assertSame(0, Payouts::closeDue('2026-11-16', $s->id)); // only once
        $this->assertTrue(AppNotification::where('user_id', $s->owner_id)->where('title', 'like', 'Payout '.$b->code.' is ready%')->exists());

        // the admin sends it by hand
        $this->as('admin@partyrent.ph', 'admin');
        $this->post("/admin/payouts/{$b->code}/pay", ['method' => 'gcash', 'reference' => ''])->assertSessionHasErrors('reference');
        $this->post("/admin/payouts/{$b->code}/pay", ['method' => 'gcash', 'reference' => 'GC-1'])->assertSessionHas('success');
        $this->assertTrue($b->fresh()->isPaid());
        $this->assertSame(0, $b->lines()->where('status', '!=', 'paid')->count());
        $this->post("/admin/payouts/{$b->code}/pay", ['method' => 'gcash', 'reference' => 'again'])->assertSessionHas('error');
    }

    public function test_monthly_shop_waits_for_the_month_end(): void
    {
        $s = $this->freshShop();
        $s->update(['payout_schedule' => 'monthly']);
        $this->earning($s, 2000, 0, '2026-11-03');
        $this->earning($s, 1000, 0, '2026-11-20');
        $this->assertSame(0, Payouts::closeDue('2026-11-16', $s->id));
        $this->assertSame(1, Payouts::closeDue('2026-12-01', $s->id));
        $this->assertEquals(3000.0, PayoutBatch::where('supplier_id', $s->id)->value('net'));
    }

    public function test_cash_above_earnings_is_carried_and_pauses_cash_after_30_days(): void
    {
        $s = $this->freshShop();
        $s->update(['methods' => ['cash', 'bank']]);
        $this->earning($s, 1000, 3000, '2026-09-03'); // collected 3,000 in cash, earned 1,000: owes 2,000

        Payouts::closeDue('2026-09-16', $s->id);
        $owed = PayoutBatch::where('supplier_id', $s->id)->firstOrFail();
        $this->assertTrue($owed->isOwed());
        $this->assertEquals(2000.0, Payouts::owed($s)['amount']);
        $this->assertTrue(AppNotification::where('user_id', $s->owner_id)->where('title', 'No payout this time')->exists());
        // over 30 days ago → cash is paused for new payments
        $this->assertTrue(Payouts::cashPaused($s));
        $this->assertSame(['bank'], $s->fresh()->acceptedMethods());

        // the next period's earnings pay it back first
        $this->earning($s, 5000, 0, '2026-10-05');
        Payouts::closeDue('2026-10-16', $s->id);
        $next = PayoutBatch::where('supplier_id', $s->id)->where('id', '!=', $owed->id)->firstOrFail();
        $this->assertEquals([-2000.0, 3000.0, 'pending'], [$next->carried_in, $next->net, $next->status]);
        $this->assertSame($next->id, $owed->fresh()->carried_to_id);
        $this->assertEquals(0.0, Payouts::owed($s)['amount']);
        $this->assertFalse(Payouts::cashPaused($s));
    }

    public function test_admin_settles_a_balance_the_shop_paid_directly(): void
    {
        $s = $this->freshShop();
        $this->earning($s, 0, 800, '2026-11-02');
        Payouts::closeDue('2026-11-16', $s->id);
        $b = PayoutBatch::where('supplier_id', $s->id)->firstOrFail();

        $this->as('admin@partyrent.ph', 'admin')->get('/admin/payouts?tab=owed')->assertOk()->assertSee($s->name)->assertSee(money(800));
        $this->post("/admin/payouts/{$b->code}/settle", ['reference' => 'BDO-55'])->assertSessionHas('success');
        $this->assertSame('settled', $b->fresh()->status);
        $this->assertEquals(0.0, Payouts::owed($s)['amount']);
        $this->post("/admin/payouts/{$b->code}/settle", ['reference' => 'BDO-55'])->assertSessionHas('error');
    }

    public function test_completed_rental_adds_its_earnings_to_the_next_payout(): void
    {
        $r = RentalRequest::where('status', 'completed')->whereHas('payout')->with('payout')->firstOrFail();
        $line = $r->payout;
        $b = Payouts::breakdown($r);
        $this->assertEquals([$b['earned'], $b['cashKept'], $b['net']], [$line->earned, $line->cash_kept, $line->net]);
        $this->assertEquals(round($line->earned - $line->cash_kept, 2), $line->net);
        $this->assertSame(RentalRequest::where('status', 'completed')->count(), Payout::count());
        // the shop's cashier cannot change the money any more
        $this->actingAs($this->shopCashier($r->code))->withSession(['mode' => 'supplier'])
            ->post('/cashier/record', ['request' => $r->code, 'type' => 'refund', 'method' => 'cash', 'amount' => 10, 'date' => today_iso(), 'reference' => 'OR-1'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already added to a payout'));
    }

    public function test_shop_cashier_sees_payouts_but_cannot_manage_them(): void
    {
        $this->as('cashier@elegantoccasion.ph', 'supplier');
        foreach (['/cashier', '/cashier/verification', '/cashier/balances', '/cashier/reports'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/cashier/payouts')->assertOk()->assertSee('Next payout')->assertDontSee('Save payout account');
        $this->get('/admin/payouts')->assertRedirect();
        $b = PayoutBatch::firstOrFail();
        $this->post("/admin/payouts/{$b->code}/pay", ['method' => 'bank', 'reference' => 'X'])->assertRedirect();
        $this->assertSame(0, PayoutBatch::where('reference', 'X')->count());
        // the schedule is the owner's choice
        $this->put('/supplier/payouts/schedule', ['payout_schedule' => 'monthly'])->assertRedirect();
        $this->assertNotSame('monthly', User::where('email', 'cashier@elegantoccasion.ph')->first()->shop()->payout_schedule);
    }

    public function test_shop_cashier_sees_only_their_shop(): void
    {
        $grace = User::where('email', 'cashier@elegantoccasion.ph')->firstOrFail();
        $sid = $grace->shop()->id;
        $mine = RentalRequest::where('supplier_id', $sid)->firstOrFail();
        $other = RentalRequest::where('supplier_id', '!=', $sid)->whereIn('status', Finance::ACTIVE)->firstOrFail();
        $theirs = \App\Models\Payment::create(['code' => 'PAY-OTHER1', 'rental_request_id' => $other->id, 'type' => 'balance', 'method' => 'cash', 'amount' => 10,
            'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'OTHER-REF-1', 'status' => 'pending']);
        \App\Models\Payment::create(['code' => 'PAY-MINE1', 'rental_request_id' => $mine->id, 'type' => 'deposit', 'method' => 'bank', 'amount' => 10,
            'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'MINE-REF-1', 'status' => 'pending']);

        $this->as('cashier@elegantoccasion.ph', 'supplier');
        $this->get('/cashier/verification')->assertOk()->assertSee('PAY-MINE1')->assertSee('PartyRent settles it')->assertDontSee('PAY-OTHER1');
        $this->get('/cashier')->assertOk()->assertDontSee('PAY-OTHER1');
        $this->get('/cashier/history?q=PAY-OTHER1')->assertOk()->assertDontSee('OTHER-REF-1');
        $this->get('/cashier/balances')->assertOk()->assertDontSee($other->code);
        $this->get('/cashier/record?request='.$other->code)->assertOk()->assertDontSee($other->customer->name);
        $this->post("/cashier/payments/{$theirs->code}/verify")->assertNotFound();
        $this->post("/cashier/payments/{$theirs->code}/reject", ['reason' => 'x'])->assertNotFound();
        $this->post('/cashier/record', ['request' => $other->code, 'type' => 'balance', 'method' => 'cash', 'amount' => 5, 'date' => today_iso(), 'reference' => 'X'])->assertNotFound();
        $this->assertSame('pending', $theirs->fresh()->status);

        // the superadmin works across every shop
        $this->as('super@partyrent.ph', 'cashier')->get('/cashier/verification')->assertOk()->assertSee('PAY-OTHER1')->assertSee('PAY-MINE1');
    }

    public function test_owner_sees_next_payout_and_sets_schedule_and_account(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $s = $juan->supplier;
        $this->as('juan@demo.ph', 'supplier')->get('/supplier/payouts')->assertOk()
            ->assertSee('Next payout')->assertSee('How payouts work')->assertSee('Held by PartyRent')->assertSee('Payout schedule');
        $this->put('/supplier/payouts/schedule', ['payout_schedule' => 'weekly'])->assertSessionHasErrors('payout_schedule');
        $this->put('/supplier/payouts/schedule', ['payout_schedule' => 'monthly'])->assertSessionHas('success', fn ($m) => str_contains($m, 'once a month'));
        $this->assertSame('monthly', $s->fresh()->payout_schedule);

        $this->put('/supplier/payouts/account', ['payout_method' => 'bank', 'payout_account_name' => 'Juan', 'payout_account_no' => '1234'])->assertSessionHasErrors('payout_bank');
        $this->put('/supplier/payouts/account', ['payout_method' => 'gcash', 'payout_bank' => 'BPI', 'payout_account_name' => 'Juan Dela Cruz', 'payout_account_no' => '0917 123 4567'])->assertSessionHas('success');
        $this->assertSame('GCash · Juan Dela Cruz · •••• 4567', $s->fresh()->payoutAccountLabel());
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
        foreach (['pending', 'owed', 'paid', 'suppliers'] as $tab) {
            $this->get('/admin/payouts?tab='.$tab)->assertOk();
        }
        $this->post("/admin/payouts/release/{$r->code}")->assertSessionHas('success', fn ($m) => str_contains($m, 'next payout'));
        $p = Payout::where('rental_request_id', $r->id)->firstOrFail();
        $this->assertEquals($b['net'], $p->net);
        $this->assertNull($p->payout_batch_id);
        $this->post("/admin/payouts/release/{$r->code}")->assertSessionHas('error'); // only once

        $live = RentalRequest::whereIn('status', Finance::ACTIVE)->firstOrFail();
        $this->post("/admin/payouts/release/{$live->code}")->assertSessionHas('error');
    }
}
