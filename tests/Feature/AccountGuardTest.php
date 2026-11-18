<?php

namespace Tests\Feature;

use App\Domain\Finance;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One sign-in per browser: pages opened for another account cannot act as the account signed in now. */
class AccountGuardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_write_from_a_page_of_another_account_is_refused(): void
    {
        $jose = $this->user('jose@demo.ph');
        $bea = $this->user('bea@demo.ph');
        $r = RentalRequest::where('customer_id', $jose->id)->where('status', 'pending')->first()
            ?? RentalRequest::where('customer_id', $jose->id)->firstOrFail();
        $r->update(['status' => 'pending']);

        // Bea is signed in now, but the page was opened for Jose: refused, nothing changes
        $this->actingAs($bea)->withSession(['mode' => 'customer'])
            ->post('/rentals/'.$r->code.'/cancel', ['reason' => 'changed plans', '_auth' => $jose->id])
            ->assertRedirect()->assertSessionHas('warn');
        $this->assertSame('pending', $r->fresh()->status);

        // JSON (checkout) gets a 409 with a clear message
        $this->actingAs($bea)->postJson('/checkout', ['orders' => [['supplier_id' => 1]]], ['X-Auth-Id' => (string) $jose->id])
            ->assertStatus(409)->assertJson(['accountChanged' => true]);

        // the matching account goes through as usual
        $this->actingAs($jose)->withSession(['mode' => 'customer'])
            ->post('/rentals/'.$r->code.'/cancel', ['reason' => 'changed plans', '_auth' => $jose->id])
            ->assertSessionHas('success');
        $this->assertSame('cancelled', $r->fresh()->status);
    }

    public function test_pages_carry_the_account_they_belong_to(): void
    {
        $jose = $this->user('jose@demo.ph');
        $this->actingAs($jose)->get('/rentals')->assertOk()->assertSee('<meta name="auth-id" content="'.$jose->id.'">', false);
    }

    public function test_shop_cashier_cannot_open_receipts_of_another_shop(): void
    {
        $grace = $this->user('cashier@elegantoccasion.ph');
        $sid = $grace->shop()->id;
        $other = RentalRequest::where('supplier_id', '!=', $sid)->whereIn('status', Finance::ACTIVE)->firstOrFail();
        $p = Payment::create(['code' => 'PAY-OTHER9', 'rental_request_id' => $other->id, 'type' => 'balance', 'method' => 'cash', 'amount' => 10,
            'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'OTHER-REF-9', 'status' => 'verified', 'receipt_no' => 'OR-OTHER9']);

        $this->actingAs($grace)->withSession(['mode' => 'cashier'])->get('/payments/'.$p->code.'/receipt')->assertNotFound();
        $this->actingAs($this->user('super@partyrent.ph'))->get('/payments/'.$p->code.'/receipt')->assertOk();
    }

    public function test_cashier_of_a_suspended_shop_does_not_see_every_shop(): void
    {
        $grace = $this->user('cashier@elegantoccasion.ph');
        $shop = $grace->shop();
        $other = RentalRequest::where('supplier_id', '!=', $shop->id)->firstOrFail();
        $shop->update(['status' => 'suspended']);

        // without an active shop there is no Business Mode, so the cashier workspace is closed to her
        $this->actingAs($grace->fresh())->withSession(['mode' => 'supplier'])
            ->get('/cashier/record?request='.$other->code)->assertRedirect()->assertDontSee($other->customer->name);
    }
}
