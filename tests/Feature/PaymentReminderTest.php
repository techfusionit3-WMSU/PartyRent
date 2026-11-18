<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\RequestMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supplier request page: remind the customer about a payment, and verify payments without leaving the page. */
class PaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_shop_reminds_the_customer_once_per_six_hours(): void
    {
        $r = $this->accepted(RentalRequest::where('status', 'pending')->firstOrFail()->code);
        $owner = User::find($r->supplier->owner_id);
        $this->actingAs($owner)->withSession(['mode' => 'supplier']);

        $this->get('/supplier/requests/'.$r->code)->assertOk()->assertSee('Notify customer')->assertSee('friendly reminder about your payment for '.$r->code);
        $this->post('/supplier/requests/'.$r->code.'/remind', ['message' => 'Please settle the down payment this week.'])->assertSessionHas('success');

        $n = AppNotification::where('user_id', $r->customer_id)->where('title', 'Payment reminder · '.$r->code)->firstOrFail();
        $this->assertStringContainsString('Please settle the down payment this week.', $n->body);
        $this->assertSame('supplier', RequestMessage::where('rental_request_id', $r->id)->latest('id')->first()->side);

        // a second one right away is refused
        $this->post('/supplier/requests/'.$r->code.'/remind', ['message' => 'Again'])->assertSessionHas('error');
        $this->assertSame(1, AppNotification::where('user_id', $r->customer_id)->where('title', 'like', 'Payment reminder%')->count());
    }

    public function test_pending_payment_is_verified_on_the_request_page(): void
    {
        $r = $this->accepted(RentalRequest::where('status', 'pending')->firstOrFail()->code);
        $owner = User::find($r->supplier->owner_id);
        $p = Payment::create(['code' => 'PAY-INL1', 'rental_request_id' => $r->id, 'type' => 'down', 'method' => 'bank', 'amount' => 100,
            'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'INL-REF-1', 'status' => 'pending']);
        // a shop without its own cashier verifies its payments itself
        \App\Models\ShopStaff::where('supplier_id', $r->supplier_id)->where('role', 'cashier')->delete();

        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get('/supplier/requests/'.$r->code)
            ->assertOk()->assertSee('action="'.route('supplier.payments.verify', $p).'"', false);
        $this->from('/supplier/requests/'.$r->code)->post(route('supplier.payments.verify', $p))->assertRedirect('/supplier/requests/'.$r->code);
        $this->assertSame('verified', $p->fresh()->status);
    }
}
