<?php

namespace Tests\Feature;

use App\Domain\Pricing;
use App\Domain\Rentals;
use App\Models\Hold;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accepting holds nothing: the items are reserved when the first payment is verified, within 24 hours.
 * First to pay wins when two accepted requests need the same items at overlapping times and the stock can't cover both.
 */
class ReserveOnDownPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private Product $p;

    private string $d;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = User::where('email', 'juan@demo.ph')->firstOrFail()->supplier;
        $this->p = Product::where('supplier_id', $this->s->id)->where('listing_type', 'rent')->where('status', 'active')->firstOrFail();
        $this->p->update(['total_stock' => 50, 'damaged' => 0, 'missing' => 0, 'written_off' => 0]);
        Hold::where('product_id', $this->p->id)->delete();
        \App\Models\LocationStock::where('product_id', $this->p->id)->delete();
        $this->d = add_days(today_iso(), 60); // no demo bookings that far ahead
        inv()->flush();
    }

    /** A pending request for $qty units on the test day (optionally a time window), then accepted by the shop. */
    private function acceptNew(int|string $qty, ?array $time = null, string $email = 'maria@demo.ph'): RentalRequest
    {
        $line = ['id' => 'l1', 'type' => 'item', 'ref_id' => $this->p->id, 'days' => [$this->d => (int) $qty]] + ($time ? ['time' => $time] : []);
        $r = RentalRequest::create([
            'code' => 'PR-D'.(++$this->seq), 'customer_id' => User::where('email', $email)->value('id'), 'supplier_id' => $this->s->id, 'event_name' => 'Party', 'event_type' => 'Birthday',
            'event_date' => $this->d, 'start_date' => $this->d, 'end_date' => $this->d, 'fulfillment' => 'pickup', 'lines' => [$line],
            'arrangement' => 'standard', 'plan' => 'standard', 'method' => 'cash', 'status' => 'pending', 'pricing' => [],
        ]);
        $r->update(['pricing' => Pricing::quoteRequest($r->fresh())]);
        inv()->flush();
        $this->actingAs($this->s->owner);
        $res = Rentals::acceptRequest($r->fresh(), null);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        inv()->flush();

        return $r->fresh();
    }

    /** The shop records the down payment in cash: verified at once. */
    private function payDown(RentalRequest $r): array
    {
        $first = $r->fresh()->fin()['schedule'][0];

        return Rentals::cashierRecord($r->fresh(), ['type' => $first['type'], 'method' => 'cash', 'amount' => $first['amount'], 'date' => today_iso()], $this->s->owner);
    }

    public function test_accepting_holds_nothing_until_the_down_payment_is_verified(): void
    {
        $r = $this->acceptNew(30);
        $this->assertTrue($r->awaitingDownPayment());
        $this->assertSame(50, inv()->availableOn($this->p->id, $this->d), 'nothing is reserved on accept');
        $this->assertSame($r->pay_by->format('Y-m-d'), $r->fin()['schedule'][0]['due'], 'the down payment is due within 24 hours');
        $this->assertNotEmpty(Rentals::stepBlockers($r, 'in_progress'), 'cannot start preparing before the down payment');

        $this->actingAs($r->customer)->get(route('rentals.show', $r))->assertOk()->assertSee('Your items are not reserved yet');

        $this->assertTrue($this->payDown($r)['ok']);
        $r->refresh();
        $this->assertFalse($r->awaitingDownPayment());
        inv()->flush();
        $this->assertSame(20, inv()->availableOn($this->p->id, $this->d));
    }

    public function test_the_accept_note_reaches_the_customer(): void
    {
        $r = $this->acceptNew(5);
        $r->update(['status' => 'pending', 'pay_by' => null]);
        $this->actingAs($this->s->owner);
        $this->assertTrue(Rentals::acceptRequest($r->fresh(), 'We will call you on your number before delivery.')['ok']);
        $this->assertDatabaseHas('request_messages', ['rental_request_id' => $r->id, 'side' => 'supplier', 'body' => 'We will call you on your number before delivery.']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $r->customer_id, 'title' => 'Request accepted 🎉']);
        $this->actingAs($r->customer)->get(route('rentals.show', $r))->assertOk()->assertSee('Message from '.$this->s->name)->assertSee('We will call you on your number before delivery.');
    }

    public function test_first_to_pay_wins_and_the_other_is_declined(): void
    {
        $a = $this->acceptNew(30);
        $b = $this->acceptNew(30, null, 'jose@demo.ph'); // both fit alone: unpaid requests hold nothing
        $this->assertTrue($this->payDown($b)['ok']);

        $this->assertNotNull($b->fresh()->reserved_at);
        $a->refresh();
        $this->assertSame('declined', $a->status);
        $this->assertStringContainsString('Another customer booked the same items for the same day and time first', $a->decline_reason);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $a->customer_id, 'title' => 'Items no longer available']);
    }

    public function test_both_are_kept_when_the_stock_covers_both(): void
    {
        $a = $this->acceptNew(20);
        $b = $this->acceptNew(20, null, 'jose@demo.ph');
        $this->assertTrue($this->payDown($a)['ok']);
        $this->assertTrue($this->payDown($b)['ok']);
        $this->assertNotNull($a->fresh()->reserved_at);
        $this->assertNotNull($b->fresh()->reserved_at);
        inv()->flush();
        $this->assertSame(10, inv()->availableOn($this->p->id, $this->d));
    }

    public function test_different_hours_on_the_same_day_do_not_clash(): void
    {
        $a = $this->acceptNew(40, ['start' => '08:00', 'end' => '11:00']);
        $b = $this->acceptNew(40, ['start' => '15:00', 'end' => '18:00'], 'jose@demo.ph');
        $this->assertTrue($this->payDown($a)['ok']);
        $this->assertTrue($this->payDown($b)['ok']);
        $this->assertSame('accepted', $b->fresh()->status);
        $this->assertNotNull($b->fresh()->reserved_at);
    }

    public function test_a_late_payment_is_refused_when_the_items_are_taken(): void
    {
        $a = $this->acceptNew(30);
        $b = $this->acceptNew(30, null, 'jose@demo.ph');
        $this->reserve($a); // a's payment reserved the items (without the automatic decline, e.g. a race)
        $first = $b->fin()['schedule'][0];
        $res = Rentals::submitPayment($b->fresh(), ['type' => $first['type'], 'method' => 'bank', 'amount' => $first['amount'], 'reference' => 'BDO-9', 'date' => today_iso()]);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('another customer booked the same items', $res['error']);
        $this->assertSame('declined', $b->fresh()->status);
        $this->assertSame(0, Payment::where('rental_request_id', $b->id)->count());
    }

    public function test_unpaid_requests_are_cancelled_after_24_hours(): void
    {
        $a = $this->acceptNew(10);
        $b = $this->acceptNew(10, null, 'jose@demo.ph');
        Payment::create(['code' => 'PAY-W1', 'rental_request_id' => $b->id, 'type' => $b->fin()['schedule'][0]['type'], 'method' => 'bank', 'amount' => 1, 'date' => today_iso(),
            'submitted_at' => now(), 'reference' => 'W1', 'status' => 'pending']); // sent in time, waiting for PartyRent to check it

        $this->travel(23)->hours();
        $this->assertSame(0, Rentals::expireUnpaid());
        $this->travel(2)->hours();
        $this->assertSame(1, Rentals::expireUnpaid());
        $this->assertSame('cancelled', $a->fresh()->status);
        $this->assertStringContainsString('24 hours', $a->fresh()->cancel_reason);
        $this->assertSame('accepted', $b->fresh()->status, 'a payment waiting for verification keeps the request');
    }
}
