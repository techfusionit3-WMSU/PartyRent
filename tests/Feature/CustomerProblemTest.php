<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\RentalRequest;
use App\Models\ShortfallCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The customer reports a problem with the items of a booking; the shop answers through the case. */
class CustomerProblemTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function booking(): RentalRequest
    {
        $r = $this->accepted(RentalRequest::where('status', 'pending')->firstOrFail()->code);
        $this->assertNotEmpty(\App\Domain\Shortfalls::bookedUnits($r));

        return $r;
    }

    public function test_customer_reports_damaged_items_and_the_shop_compensates(): void
    {
        $r = $this->booking();
        $customer = $r->customer;
        [$pid, $days] = [array_key_first(\App\Domain\Shortfalls::bookedUnits($r)), current(\App\Domain\Shortfalls::bookedUnits($r))];

        $this->actingAs($customer)->withSession(['mode' => 'customer'])->get('/rentals/'.$r->code)->assertOk()->assertSee('Report a problem');
        $this->post('/rentals/'.$r->code.'/problem', ['product_id' => $pid, 'problem' => 'damaged', 'qty' => 1, 'details' => 'One arrived with a broken leg.'])
            ->assertRedirect()->assertSessionHas('success');

        $c = ShortfallCase::where('rental_request_id', $r->id)->firstOrFail();
        $this->assertSame('problem', $c->kind);
        $this->assertSame('damaged', $c->problem);
        $this->assertSame(array_keys($days), array_keys($c->short));
        $this->assertNotNull($c->deadline_at); // the shop must answer in time or support steps in
        $this->assertTrue(AppNotification::where('user_id', $r->supplier->owner_id)->where('title', 'Problem reported on '.$r->code)->exists());
        $this->get(route('cases.show', $c))->assertOk()->assertSee('reported a problem')->assertSee('Damaged or not working on arrival');

        // the customer can't propose first, and can't be charged
        $this->post(route('cases.propose', $c), ['type' => 'refund', 'credit' => 100])->assertSessionHas('error');
        $owner = User::find($r->supplier->owner_id);
        $this->actingAs($owner)->withSession(['mode' => 'supplier']);
        $this->post(route('cases.propose', $c), ['type' => 'compensation', 'comp_amount' => 50, 'comp_payer' => 'customer', 'comp_reason' => 'Handling'])->assertSessionHas('error');
        $this->post(route('cases.propose', $c), ['type' => 'compensation', 'comp_amount' => 150, 'comp_payer' => 'supplier', 'comp_reason' => 'Sorry for the broken chair'])->assertSessionHas('success');
        $total = (float) $r->fresh()->pricing['total'];
        $this->actingAs($customer)->withSession(['mode' => 'customer'])->post(route('cases.respond', $c), ['answer' => 'accept'])->assertSessionHas('success');
        $this->assertSame('resolved', $c->fresh()->status);
        $this->assertEqualsWithDelta($total, (float) $r->fresh()->pricing['total'], 0.001); // nothing added to the customer's bill
    }

    public function test_reporting_rules(): void
    {
        $r = $this->booking();
        $pid = array_key_first(\App\Domain\Shortfalls::bookedUnits($r));
        $this->actingAs($r->customer)->withSession(['mode' => 'customer']);

        // an item that isn't in the booking, too many units, no description
        $other = \App\Models\Product::whereNotIn('id', array_keys(\App\Domain\Shortfalls::bookedUnits($r)))->firstOrFail();
        $this->post('/rentals/'.$r->code.'/problem', ['product_id' => $other->id, 'problem' => 'missing', 'qty' => 1, 'details' => 'Not delivered at all'])->assertSessionHas('error');
        $this->post('/rentals/'.$r->code.'/problem', ['product_id' => $pid, 'problem' => 'missing', 'qty' => 99999, 'details' => 'Not delivered at all'])->assertSessionHas('error');
        $this->post('/rentals/'.$r->code.'/problem', ['product_id' => $pid, 'problem' => 'missing', 'qty' => 1, 'details' => 'x'])->assertSessionHasErrors('details');
        $this->assertSame(0, ShortfallCase::count());

        // one open case per item; another customer can't report on this booking
        $this->post('/rentals/'.$r->code.'/problem', ['product_id' => $pid, 'problem' => 'missing', 'qty' => 1, 'details' => 'Two chairs missing'])->assertSessionHas('success');
        $this->post('/rentals/'.$r->code.'/problem', ['product_id' => $pid, 'problem' => 'late', 'qty' => 1, 'details' => 'Also late'])->assertSessionHas('error');
        $this->actingAs(User::where('email', 'bea@demo.ph')->firstOrFail())->post('/rentals/'.$r->code.'/problem', ['product_id' => $pid, 'problem' => 'missing', 'qty' => 1, 'details' => 'Two chairs missing'])->assertNotFound();
        $this->assertSame(1, ShortfallCase::count());
    }
}
