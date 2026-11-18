<?php

namespace Tests\Feature;

use App\Domain\BookingDays;
use App\Domain\Pricing;
use App\Domain\Shortfalls;
use App\Models\AppNotification;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\ShortfallCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Change list C12: shortfall report → cases → proposals / counter / decline → deadline → support → applied. */
class ShortfallCasesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private int $seq = 0;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function chairs(int $stock = 100, string $name = 'Test Chair'): Product
    {
        $juan = $this->user('juan@demo.ph');

        return Product::create([
            'supplier_id' => $juan->supplier->id, 'category_id' => Product::where('supplier_id', $juan->supplier->id)->value('category_id'), 'name' => $name.' '.(++$this->seq),
            'description' => 'Chairs for tests', 'total_stock' => $stock, 'unit' => 'piece / day', 'tiers' => [['min' => 1, 'max' => null, 'price' => 10]],
            'colors' => [], 'themes' => [], 'events' => [], 'images' => [], 'status' => 'active', 'listing_type' => 'rent',
        ]);
    }

    /** An accepted, fully paid booking of $p: [date => qty]. */
    private function booking(User $customer, Product $p, array $days, string $acceptedAt): RentalRequest
    {
        $dates = array_keys($days);
        $r = RentalRequest::create([
            'code' => 'PR-S'.(++$this->seq), 'customer_id' => $customer->id, 'supplier_id' => $p->supplier_id, 'event_name' => 'Party '.$this->seq, 'event_type' => 'Birthday',
            'event_date' => $dates[0], 'start_date' => min($dates), 'end_date' => max($dates), 'fulfillment' => 'pickup',
            'lines' => [['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => $days]], 'arrangement' => 'full', 'method' => 'bank', 'plan' => 'full',
            'status' => 'accepted', 'pricing' => [], 'accepted_at' => $acceptedAt,
        ]);
        $r->update(['pricing' => Pricing::quoteRequest($r->fresh())]);
        Payment::create(['code' => 'PAY-S'.$this->seq, 'rental_request_id' => $r->id, 'type' => 'full', 'method' => 'bank', 'amount' => $r->pricing['total'], 'date' => today_iso(),
            'submitted_at' => now(), 'reference' => 'REF-S'.$this->seq, 'status' => 'verified', 'verified_at' => now()]);
        BookingDays::sync($r->fresh());
        inv()->flush();

        return $r->fresh();
    }

    private function asSupplier(): static
    {
        return $this->actingAs($this->user('juan@demo.ph'))->withSession(['mode' => 'supplier']);
    }

    private function asCustomer(User $u): static
    {
        return $this->actingAs($u)->withSession(['mode' => 'customer']);
    }

    /** Two bookings 60 + 30 of 100 chairs; 30 lost on d1 → only the later-accepted booking is short (20). */
    private function scenario(): array
    {
        $p = $this->chairs();
        $d1 = add_days(today_iso(), 40);
        $d2 = add_days($d1, 1);
        $maria = $this->user('maria@demo.ph');
        $carlo = $this->user('carlo@demo.ph');
        $a = $this->booking($maria, $p, [$d1 => 60, $d2 => 60], add_days(today_iso(), -3));
        $b = $this->booking($carlo, $p, [$d1 => 30, $d2 => 30], today_iso());
        $this->asSupplier()->post(route('supplier.shortfalls.store'), ['product_id' => $p->id, 'qty' => 30, 'start_date' => $d1, 'end_date' => $d1, 'reason' => '30 chairs broke in transport.'])
            ->assertSessionHas('success');

        return [$p, $a, $b, $d1, $d2, $maria, $carlo];
    }

    public function test_report_flags_affected_bookings_and_notifies(): void
    {
        [$p, $a, $b, $d1, , , $carlo] = $this->scenario();
        $this->assertSame(1, ShortfallCase::count());
        $c = ShortfallCase::first();
        $this->assertSame($b->id, $c->rental_request_id); // most recently accepted booking absorbs the shortage
        $this->assertSame([$d1 => 20], $c->short); // 90 booked, 70 usable
        $this->assertSame('reported', $c->status);
        $this->assertSame(70, $p->total_stock - inv()->unusableOn($p->fresh(), $d1)); // units out of stock
        $this->assertTrue(AppNotification::where('user_id', $carlo->id)->where('title', 'like', 'Problem with your booking%')->exists());
        $this->asSupplier()->get(route('supplier.shortfalls'))->assertOk()->assertSee($c->code)->assertSee('Respond');
        $this->asCustomer($carlo)->get(route('rentals.show', $b))->assertOk()->assertSee('Case '.$c->code);
        $this->asCustomer($carlo)->get(route('cases.show', $c))->assertOk()->assertSee('30 chairs broke in transport.');
        // nobody else can open it
        $this->asCustomer($this->user('maria@demo.ph'))->get(route('cases.show', $c))->assertForbidden();
    }

    public function test_propose_decline_counter_accept_updates_price_and_refund(): void
    {
        [$p, $a, $b, $d1, , , $carlo] = $this->scenario();
        $c = ShortfallCase::first();
        $total = (float) $b->pricing['total'];

        $this->asSupplier()->post(route('cases.propose', $c), ['type' => 'delay', 'note' => 'The last 20 arrive at noon.', 'deadline_hours' => 24])->assertSessionHas('success');
        $this->assertSame('proposed', $c->fresh()->status);
        // the shop cannot answer its own proposal
        $this->asSupplier()->post(route('cases.respond', $c), ['answer' => 'accept'])->assertSessionHas('error');
        $this->asCustomer($carlo)->post(route('cases.respond', $c), ['answer' => 'decline', 'note' => 'Too late for us.'])->assertSessionHas('success');
        $this->assertSame('countered', $c->fresh()->status);
        // customer counters with a refund; the shop accepts → price, refund and timeline follow
        $this->asCustomer($carlo)->post(route('cases.propose', $c), ['type' => 'refund', 'credit' => 150, 'note' => 'Refund the 20 chairs and the hassle.'])->assertSessionHas('success');
        $p->update(['tiers' => [['min' => 1, 'max' => null, 'price' => 50]]]); // later price changes never touch the quoted price
        $this->asSupplier()->post(route('cases.respond', $c), ['answer' => 'accept'])->assertSessionHas('success');
        $c->refresh();
        $b->refresh();
        $this->assertSame('resolved', $c->status);
        $this->assertEqualsWithDelta($total - 150, $b->pricing['total'], 0.001);
        $this->assertSame(150.0, (float) Payment::where('rental_request_id', $b->id)->where('type', 'refund')->where('status', 'pending')->value('amount'));
        $this->assertEqualsWithDelta(150, $c->resolution['refund'], 0.001);
        $this->assertSame(['report', 'proposal', 'decline', 'counter', 'accept', 'apply'], $c->events()->pluck('kind')->all());
        $this->asCustomer($carlo)->get(route('cases.show', $c))->assertOk()->assertSee('Resolution')->assertSee('is on its way back to you');
    }

    public function test_partial_and_substitute_change_the_booking_lines(): void
    {
        [$p, $a, $b, $d1, $d2, , $carlo] = $this->scenario();
        $c = ShortfallCase::first();
        // partial: 20 fewer chairs on d1, price follows the units
        $this->asSupplier()->post(route('cases.propose', $c), ['type' => 'partial', 'credit' => 0])->assertSessionHas('success');
        $p->update(['tiers' => [['min' => 1, 'max' => null, 'price' => 20]]]); // removed units come off at the booked ₱10
        $this->asCustomer($carlo)->post(route('cases.respond', $c), ['answer' => 'accept'])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame([$d1 => 10, $d2 => 30], $b->lines[0]['days']);
        $this->assertEqualsWithDelta(40 * 10, $b->pricing['total'], 0.001); // 40 chair-days × ₱10
        $this->assertSame(200.0, (float) Payment::where('rental_request_id', $b->id)->where('type', 'refund')->value('amount'));
        $this->assertSame(0, inv()->availableOn($p->id, $d1)); // 60 + 10 booked of 70 usable

        // substitute on a second shortfall: Maria's 60 on d2 when all 100 are lost that day
        $sub = $this->chairs(100, 'Spare Chair');
        $this->asSupplier()->post(route('supplier.shortfalls.store'), ['product_id' => $p->id, 'qty' => 100, 'start_date' => $d2, 'reason' => 'Flooded warehouse.']);
        $cm = ShortfallCase::where('rental_request_id', $a->id)->firstOrFail();
        $this->assertSame([$d2 => 60], $cm->short);
        $this->asSupplier()->post(route('cases.propose', $cm), ['type' => 'substitute', 'substitute_id' => $sub->id])->assertSessionHas('success');
        $this->asCustomer($this->user('maria@demo.ph'))->post(route('cases.respond', $cm), ['answer' => 'accept'])->assertSessionHas('success');
        $a->refresh();
        $this->assertSame([$d1 => 60], $a->lines[0]['days']);
        $this->assertSame($sub->id, $a->lines[1]['ref_id']);
        $this->assertSame([$d2 => 60], $a->lines[1]['days']);
        $this->assertSame(40, inv()->availableOn($sub->id, $d2));
    }

    public function test_deadline_escalates_to_support_who_settles(): void
    {
        [$p, $a, $b, $d1, , , $carlo] = $this->scenario();
        $c = ShortfallCase::first();
        $this->asSupplier()->post(route('cases.propose', $c), ['type' => 'cancel', 'deadline_hours' => 12])->assertSessionHas('success');
        $this->travel(13)->hours();
        $this->assertSame(1, Shortfalls::escalateDue());
        $c->refresh();
        $this->assertSame('escalated', $c->status);
        $this->assertTrue(AppNotification::where('role', 'admin')->where('title', 'like', 'Shortfall case escalated%')->exists());
        // the customer can no longer accept the expired proposal
        $this->asCustomer($carlo)->post(route('cases.respond', $c), ['answer' => 'accept'])->assertSessionHas('error');

        $admin = User::whereJsonContains('roles', 'admin')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->get(route('admin.cases'))->assertOk()->assertSee($c->code)->assertSee('Settle');
        $proposal = $c->events()->where('kind', 'proposal')->value('id');
        $this->post(route('cases.settle', $c), ['proposal' => $proposal, 'note' => 'Cancelling with a full refund.'])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame('cancelled', $b->status);
        $this->assertEqualsWithDelta((float) $b->pricing['total'], (float) Payment::where('rental_request_id', $b->id)->where('type', 'refund')->value('amount'), 0.001);
        $this->assertSame('admin', $c->fresh()->resolution['by']);
        $this->assertSame(0, \App\Models\BookingDay::where('rental_request_id', $b->id)->whereIn('status', RentalRequest::RESERVING)->count());
    }

    public function test_report_without_affected_bookings_and_validation(): void
    {
        $p = $this->chairs();
        $d = add_days(today_iso(), 50);
        $this->booking($this->user('maria@demo.ph'), $p, [$d => 10], today_iso());
        $this->asSupplier()->post(route('supplier.shortfalls.store'), ['product_id' => $p->id, 'qty' => 20, 'start_date' => $d, 'reason' => 'Some broke.'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'spare stock still covers'));
        $this->assertSame(0, ShortfallCase::count());
        $this->asSupplier()->post(route('supplier.shortfalls.store'), ['product_id' => $p->id, 'qty' => 500, 'start_date' => $d, 'reason' => 'Too many.'])->assertSessionHas('error');
        $this->asSupplier()->post(route('supplier.shortfalls.store'), ['product_id' => $p->id, 'qty' => 1, 'start_date' => add_days(today_iso(), -1), 'reason' => 'Past.'])->assertSessionHasErrors('start_date');
    }

    public function test_only_the_shop_compensates_and_proposes_first(): void
    {
        [$p, $a, $b, $d1, , , $carlo] = $this->scenario();
        $c = ShortfallCase::first();
        $total = (float) $b->pricing['total'];

        // the customer can't jump ahead of the shop's first proposal
        $this->asCustomer($carlo)->post(route('cases.propose', $c), ['type' => 'compensation', 'comp_amount' => 500, 'comp_payer' => 'supplier', 'comp_reason' => 'Rush fee'])->assertSessionHas('error');
        // compensation-only needs an amount and a reason
        $this->asSupplier()->post(route('cases.propose', $c), ['type' => 'compensation'])->assertSessionHas('error');
        $this->asSupplier()->post(route('cases.propose', $c), ['type' => 'compensation', 'comp_amount' => 100])->assertSessionHas('error');
        // the shop's mistake never makes the customer pay: no "customer pays" compensation, whatever the type
        foreach (['compensation', 'substitute', 'partial', 'cancel'] as $type) {
            $this->asSupplier()->post(route('cases.propose', $c), ['type' => $type, 'comp_amount' => 80, 'comp_payer' => 'customer', 'comp_reason' => 'Rush delivery'])->assertSessionHas('error');
        }
        $this->assertSame(0, \App\Models\CaseEvent::where('shortfall_case_id', $c->id)->where('kind', 'proposal')->count());

        // the shop proposes fewer units; the customer counters: the shop pays them ₱300 for renting elsewhere at short notice
        $this->asSupplier()->post(route('cases.propose', $c), ['type' => 'partial'])->assertSessionHas('success');
        $this->asCustomer($carlo)->get(route('cases.show', $c))->assertOk()->assertSee('Compensation')->assertDontSee('I cover the shop');
        $this->asCustomer($carlo)->post(route('cases.propose', $c), ['type' => 'partial', 'comp_amount' => 300, 'comp_payer' => 'supplier', 'comp_reason' => 'Rush fee to rent 20 chairs elsewhere'])->assertSessionHas('success');
        $this->asSupplier()->post(route('cases.respond', $c), ['answer' => 'accept'])->assertSessionHas('success');
        $c->refresh();
        $this->assertSame('resolved', $c->status);
        $this->assertSame(300.0, (float) $c->resolution['comp']['amount']);
        $comp = Payment::where('rental_request_id', $b->id)->where('type', 'refund')->where('notes', 'like', 'Compensation%')->firstOrFail();
        $this->assertSame(300.0, (float) $comp->amount);
        $this->assertLessThan($total, (float) $b->fresh()->pricing['total']); // 20 chairs came off
    }

    public function test_a_pricier_substitute_costs_the_customer_nothing_more(): void
    {
        [$p, $a, $b, $d1, , , $carlo] = $this->scenario();
        $c = ShortfallCase::first();
        $total = (float) $b->pricing['total'];
        $sub = $this->chairs(100, 'Premium Chair');
        $sub->update(['tiers' => [['min' => 1, 'max' => null, 'price' => 35]]]); // 3.5x the booked chair

        $this->asSupplier()->post(route('cases.propose', $c), ['type' => 'substitute', 'substitute_id' => $sub->id])->assertSessionHas('success');
        $this->asCustomer($carlo)->post(route('cases.respond', $c), ['answer' => 'accept'])->assertSessionHas('success');
        $b->refresh();
        $this->assertEqualsWithDelta($total, (float) $b->pricing['total'], 0.001);
        $this->assertTrue(collect($b->pricing['credits'] ?? [])->contains(fn ($x) => str_contains($x['label'], 'Substitute price difference covered')));
        $this->assertTrue(collect($b->lines)->contains(fn ($l) => (int) $l['ref_id'] === $sub->id));
    }
}
