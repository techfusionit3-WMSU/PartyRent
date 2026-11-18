<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cash (formerly "Cash on Delivery"): the customer pays the shop in person and the shop records it. */
class CashPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_no_cod_left_and_cash_is_labelled_cash(): void
    {
        $this->assertSame(0, RentalRequest::where('method', 'cod')->count());
        $this->assertSame('Cash', \App\Domain\Finance::METHOD_LABEL['cash']);
    }

    public function test_shop_records_cash_paid_in_person_without_a_reference(): void
    {
        $r = $this->accepted(RentalRequest::where('status', 'pending')->firstOrFail()->code);
        $owner = User::find($r->supplier->owner_id);
        $amount = min(500, $r->fin()['balance']);

        $this->actingAs($owner)->withSession(['mode' => 'supplier'])
            ->post('/supplier/requests/'.$r->code.'/cash', ['type' => 'down', 'method' => 'cash', 'amount' => $amount, 'date' => today_iso()])
            ->assertSessionHas('success');

        $p = Payment::where('rental_request_id', $r->id)->latest('id')->firstOrFail();
        $this->assertSame('verified', $p->status);
        $this->assertSame('cash', $p->method);
        $this->assertNotEmpty($p->receipt_no);
        $this->assertStringStartsWith('IN-PERSON', $p->reference);
        $this->assertTrue(AppNotification::where('user_id', $r->customer_id)->where('scope', 'customer')->where('title', 'like', 'Payment verified%')->exists());

        // a bank transfer still needs its reference number
        $this->actingAs($owner)->post('/supplier/requests/'.$r->code.'/cash', ['type' => 'balance', 'method' => 'bank', 'amount' => 1, 'date' => today_iso()])
            ->assertSessionHas('error');
    }
}
