<?php

namespace Tests\Unit;

use App\Domain\Finance;
use App\Domain\Payouts;
use App\Domain\Pricing;
use App\Domain\Slots;
use App\Models\Category;
use App\Models\Hold;
use App\Models\InventoryLocation;
use App\Models\LocationStock;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The business rules from the reference README / js/core (inventory, slots, pricing, finance) and the
 * change-list payout formula. Each test builds its own small data set.
 */
class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    // Seed like the feature tests: RefreshDatabase only seeds on the first migration of a run.
    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private User $c;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Category::firstOrCreate(['id' => 'chairs'], [ 'name' => 'Chairs', 'icon' => 'chair', 'grad_from' => '#000000', 'grad_to' => '#ffffff', 'active' => true, 'name' => 'Chairs']);
        $owner = User::create(['name' => 'Owner One', 'email' => 'o@x.ph', 'password' => 'secret123', 'roles' => ['customer'], 'status' => 'active']);
        $this->c = User::create(['name' => 'Cust One', 'email' => 'c@x.ph', 'password' => 'secret123', 'roles' => ['customer'], 'status' => 'active']);
        $this->s = Supplier::create([
            'owner_id' => $owner->id, 'name' => 'Test Rentals', 'description' => 'x', 'city' => 'Quezon City', 'address' => 'x', 'phone' => '1', 'email' => 'o@x.ph',
            'methods' => ['bank'], 'arrangements' => [], 'policy' => ['depositType' => 'fixed', 'depositValue' => 1000], 'status' => 'approved',
            'payment_plans' => ['standard' => ['enabled' => true, 'resPct' => 10, 'resDays' => 3, 'downPct' => 30, 'downDays' => 10], 'full' => ['enabled' => true, 'days' => 5],
                'installment' => ['enabled' => true, 'resPct' => 10, 'resDays' => 3, 'downPct' => 20, 'downDays' => 14, 'minMonths' => 2, 'maxInstallments' => 4]],
            'time_settings' => ['open' => '06:00', 'close' => '22:00', 'prepHours' => 0, 'recoveryHours' => 0],
            'delivery_zones' => [['id' => 'z1', 'name' => 'Quezon City', 'fee' => 350], ['id' => 'z2', 'name' => 'Marikina', 'fee' => 450]],
            'services' => [['id' => 'sv1', 'name' => 'Setup', 'amount' => 300, 'per' => 'location', 'required' => false], ['id' => 'sv2', 'name' => 'Prep', 'amount' => 250, 'per' => 'order', 'required' => true]],
        ]);
    }

    private function product(int $stock, array $tiers = [[1, null, 10]]): Product
    {
        return Product::create([
            'supplier_id' => $this->s->id, 'category_id' => 'chairs', 'name' => 'Chair '.(++$this->seq), 'description' => '', 'total_stock' => $stock,
            'tiers' => array_map(fn ($t) => ['min' => $t[0], 'max' => $t[1], 'price' => $t[2]], $tiers),
            'colors' => [], 'themes' => [], 'events' => [], 'images' => [], 'status' => 'active',
        ]);
    }

    private function book(Product $p, array $days, ?array $time = null, string $status = 'accepted', array $x = []): RentalRequest
    {
        $line = ['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => $days] + ($time ? ['time' => $time] : []) + ($x['line'] ?? []);
        $dates = array_keys($days);
        $r = RentalRequest::create([
            'code' => 'PR-T'.(++$this->seq), 'customer_id' => $this->c->id, 'supplier_id' => $this->s->id, 'event_name' => 'E', 'event_type' => 'Birthday',
            'event_date' => $x['event'] ?? $dates[0], 'start_date' => min($dates), 'end_date' => max($dates), 'fulfillment' => 'pickup', 'lines' => [$line],
            'arrangement' => 'full', 'method' => 'bank', 'status' => $status, 'pricing' => [], 'accepted_at' => $x['accepted'] ?? null, 'plan' => $x['plan'] ?? 'full',
            'reserved_at' => $status === 'pending' ? null : now(), // a booking that holds stock (down payment in)
        ]);
        $r->update(['pricing' => Pricing::quoteRequest($r->fresh())]);
        inv()->flush();

        return $r->fresh();
    }

    /* ---------- inventory.js ---------- */

    public function test_available_is_total_minus_maintenance_minus_booked_that_day(): void
    {
        $mon = add_days(today_iso(), 7);
        $tue = add_days($mon, 1);
        $p = $this->product(120);
        Hold::create(['product_id' => $p->id, 'type' => 'maintenance', 'qty' => 20, 'start_date' => $mon, 'end_date' => $tue, 'note' => '']);
        $this->book($p, [$mon => 20]);

        $this->assertSame(80, inv()->availableOn($p->id, $mon));
        $this->assertSame(100, inv()->availableOn($p->id, $tue));
        // a period is the lowest daily figure, not total minus every booking
        $this->assertSame(80, inv()->minAvailable($p->id, [$mon, $tue]));
    }

    public function test_pending_never_consumes_and_damaged_missing_written_off_do(): void
    {
        $d = add_days(today_iso(), 5);
        $p = $this->product(50);
        $this->book($p, [$d => 30], null, 'pending');
        $this->assertSame(50, inv()->availableOn($p->id, $d));
        $this->assertSame(30, inv()->breakdown($p->id, $d)['pending']);
        $p->update(['damaged' => 2, 'missing' => 1, 'written_off' => 2]);
        inv()->flush();
        $this->assertSame(45, inv()->availableOn($p->id, $d));
    }

    /* ---------- slots.js ---------- */

    public function test_time_based_stock_uses_peak_overlap(): void
    {
        $d = add_days(today_iso(), 3);
        $p = $this->product(50);
        $am = [6 * 60, 11 * 60];
        $pm = [13 * 60, 18 * 60];
        $this->book($p, [$d => 25], ['start' => '06:00', 'end' => '11:00']);
        $this->assertSame(25, inv()->availableOn($p->id, $d, null, $am));
        $this->assertSame(50, inv()->availableOn($p->id, $d, null, $pm));

        $this->book($p, [$d => 20], ['start' => '13:00', 'end' => '18:00']);
        $this->assertSame(25, inv()->availableOn($p->id, $d, null, $am));
        $this->assertSame(30, inv()->availableOn($p->id, $d, null, $pm));
        // the whole day is limited by the busiest moment, not 50 − 25 − 20
        $this->assertSame(25, inv()->availableOn($p->id, $d));
    }

    public function test_buffers_extend_a_booking(): void
    {
        $this->s->update(['time_settings' => ['open' => '06:00', 'close' => '22:00', 'prepHours' => 2, 'recoveryHours' => 2]]);
        $d = add_days(today_iso(), 3);
        $p = $this->product(50);
        $this->book($p, [$d => 25], ['start' => '06:00', 'end' => '11:00']);
        // 11:00 + 2 h recovery overlaps a 12:00 start
        $this->assertSame(25, inv()->availableOn($p->id, $d, null, [12 * 60, 15 * 60]));
        $this->assertSame(50, inv()->availableOn($p->id, $d, null, [14 * 60, 18 * 60]));
    }

    public function test_check_combines_lines_and_respects_location_stock(): void
    {
        $d = add_days(today_iso(), 4);
        $p = $this->product(100);
        $l1 = InventoryLocation::create(['supplier_id' => $this->s->id, 'name' => 'Main']);
        $l2 = InventoryLocation::create(['supplier_id' => $this->s->id, 'name' => 'Branch']);
        LocationStock::create(['inventory_location_id' => $l1->id, 'product_id' => $p->id, 'qty' => 60]);
        LocationStock::create(['inventory_location_id' => $l2->id, 'product_id' => $p->id, 'qty' => 40]);
        inv()->flush();

        $lines = [
            ['id' => 'a', 'type' => 'item', 'ref_id' => $p->id, 'days' => [$d => 30], 'sourceId' => $l2->id],
            ['id' => 'b', 'type' => 'item', 'ref_id' => $p->id, 'days' => [$d => 20], 'sourceId' => $l2->id],
        ];
        $res = inv()->check($lines);
        $this->assertFalse($res['ok']); // 50 from a location that holds 40
        $this->assertSame($l2->id, $res['bad'][0]['location_id']);
        $lines[1]['sourceId'] = $l1->id;
        $this->assertTrue(inv()->check($lines)['ok']);
        $this->assertSame(40, inv()->availableAtLocation($p->id, $l2->id, $d));
    }

    public function test_slots_helpers(): void
    {
        $this->assertSame('6:00 AM', Slots::fmt12('06:00'));
        $this->assertSame('1:30 PM', Slots::fmt12('13:30'));
        $d = today_iso();
        $this->assertSame('5 Hours', Slots::duration(['days' => [$d => 1], 'time' => ['start' => '06:00', 'end' => '11:00']])['label']);
        $this->assertSame('2 Days (all day)', Slots::duration(['days' => [$d => 1, add_days($d, 1) => 1]])['label']);
    }

    public function test_bulk_discount_line(): void
    {
        $this->assertSame('', Pricing::bulkLine($this->product(10)));
        $this->assertSame('₱5 off per piece for orders of 100+', Pricing::bulkLine($this->product(10, [[1, 99, 20], [100, null, 15]])));
        $this->assertSame('₱2.50 off per piece for orders of 11+ · up to ₱5 off at 51+', Pricing::bulkLine($this->product(10, [[1, 10, 15], [11, 50, 12.5], [51, null, 10]])));
    }

    public function test_item_time_frame_and_overnight_rule(): void
    {
        $d = add_days(today_iso(), 4);
        $next = add_days($d, 1);
        $p = $this->product(10);
        $line = fn (array $days, string $a, string $b) => ['type' => 'item', 'ref_id' => $p->id, 'days' => $days, 'time' => ['start' => $a, 'end' => $b]];

        // no frame: only the shop's rental hours apply, back-to-back dates are fine
        $this->assertSame([], Slots::validateLine($line([$d => 1, $next => 1], '08:00', '17:00')));

        $p->update(['time_from' => '08:00', 'time_to' => '17:00', 'allow_overnight' => false]);
        $this->assertSame(['start' => '08:00', 'end' => '17:00'], Slots::defaultTime($this->s, $p));
        $this->assertSame([], Slots::validateLine($line([$d => 1], '09:00', '16:00')));
        $this->assertStringContainsString('only be rented between 8:00 AM and 5:00 PM', implode(' ', Slots::validateLine($line([$d => 1], '07:00', '16:00'))));
        $this->assertStringContainsString('cannot be kept overnight', implode(' ', Slots::validateLine($line([$d => 1, $next => 1], '09:00', '16:00'))));

        $p->update(['allow_overnight' => true]);
        $this->assertSame([], Slots::validateLine($line([$d => 1, $next => 1], '09:00', '16:00')));

        // a frame past midnight (with overnight): 6 PM – 8 AM
        $p->update(['time_from' => '18:00', 'time_to' => '08:00']);
        $this->assertSame([], Slots::validateLine($line([$d => 1, $next => 1], '19:00', '07:00')));
        $this->assertNotSame([], Slots::validateLine($line([$d => 1, $next => 1], '12:00', '07:00')));
    }

    /* ---------- pricing.js ---------- */

    public function test_tier_per_date_uses_combined_quantity_and_charges(): void
    {
        $d1 = add_days(today_iso(), 10);
        $d2 = add_days($d1, 1);
        $p = $this->product(200, [[1, 10, 15], [11, 50, 12], [51, null, 10]]);
        $q = Pricing::quoteOrder([
            'supplier' => $this->s, 'fulfillment' => 'delivery', 'plan' => 'standard', 'services' => ['sv1'],
            'destinations' => [['id' => 'd1', 'label' => 'Home', 'zoneId' => 'z1'], ['id' => 'd2', 'label' => 'Hall', 'zoneId' => 'z2']],
            'lines' => [
                ['id' => 'l1', 'type' => 'item', 'ref_id' => $p->id, 'days' => [$d1 => 30, $d2 => 5], 'destId' => 'd1'],
                ['id' => 'l2', 'type' => 'item', 'ref_id' => $p->id, 'days' => [$d1 => 30], 'destId' => 'd2'],
            ],
        ]);
        // d1: 60 combined → ₱10 each (splitting between venues never changes the tier); d2: 5 → ₱15
        $this->assertSame(375.0, $q['lines'][0]['total']);
        $this->assertSame(30 * 10.0, $q['lines'][1]['total']);
        $this->assertSame(675.0, $q['rentalFee']);
        $this->assertSame(800.0, $q['deliveryFee']); // 350 + 450
        // services: Prep (required, per order) + Setup per visit: d1 at Home on 2 consecutive days = 1, Hall = 1
        $this->assertSame(250.0 + 300 * 2, $q['serviceFee']);
        $this->assertSame(675.0 + 800 + 850, $q['total']);
        // Standard plan: no reservation fee; down payment credited, Final = Total − DP
        $this->assertSame(0.0, $q['reservationFee']);
        $this->assertSame(r2($q['total'] * 0.30), $q['downPayment']);
        $this->assertSame(r2($q['total'] - $q['downPayment']), $q['finalBalance']);
        $this->assertSame(1000.0, $q['deposit']);
    }

    public function test_service_visits_rule(): void
    {
        $d = add_days(today_iso(), 10);
        $line = fn ($days, $dest) => ['type' => 'item', 'ref_id' => 1, 'days' => array_fill_keys($days, 1), 'destId' => $dest];
        $dests = [['id' => 'A'], ['id' => 'B']];
        // consecutive days, same place → 1
        $this->assertSame(1, Pricing::serviceVisits([$line([$d, add_days($d, 1), add_days($d, 2)], 'A')], $dests));
        // consecutive days, place changes → 2
        $this->assertSame(2, Pricing::serviceVisits([$line([$d, add_days($d, 1)], 'A'), $line([add_days($d, 2)], 'B')], $dests));
        // separate date blocks → one per block
        $this->assertSame(2, Pricing::serviceVisits([$line([$d, add_days($d, 5)], 'A')], $dests));
        // pickup: separate date blocks
        $this->assertSame(2, Pricing::serviceVisits([$line([$d, add_days($d, 3)], null)]));
    }

    /* ---------- finance.js ---------- */

    public function test_only_verified_payments_count_and_statuses(): void
    {
        $ev = add_days(today_iso(), 20);
        $p = $this->product(100, [[1, null, 100]]);
        $r = $this->book($p, [$ev => 10], null, 'accepted', ['plan' => 'standard', 'accepted' => today_iso()]);
        $total = $r->pricing['total'];
        $this->assertEquals(1250.0, $total); // 10 × ₱100 + required ₱250 service
        $sched = Finance::schedule($r);
        $this->assertSame(['down', 'bal'], array_column($sched, 'key'));
        $this->assertSame([375.0, 875.0], array_column($sched, 'amount'));
        $this->assertSame(add_days(today_iso(), 1), $sched[0]['due']); // the reserving payment: within Rentals::PAY_HOURS (24 h) of acceptance
        $this->assertSame($ev, $sched[1]['due']);

        $pay = fn ($amt, $type, $status) => Payment::create(['code' => 'TXN-'.(++$this->seq), 'rental_request_id' => $r->id, 'type' => $type, 'method' => 'bank', 'amount' => $amt, 'date' => today_iso(), 'status' => $status, 'submitted_at' => now()->addSeconds($this->seq)]);
        $pay(375, 'down', 'pending');
        $this->assertSame('Pending', $r->fresh()->fin()['status']);
        $pay(375, 'down', 'verified');
        // the verified down payment reserves the booking and marks it Partially Paid at once
        $f = Finance::summary($r->fresh());
        $this->assertSame('Partially Paid', $f['status']);
        $this->assertTrue($f['reservationConfirmed']);
        $this->assertSame(875.0, $f['balance']);
        $pay(875, 'balance', 'verified');
        $this->assertSame('Fully Paid', Finance::summary($r->fresh())['status']);
    }

    public function test_overdue_but_never_cancelled(): void
    {
        $ev = add_days(today_iso(), 20);
        $p = $this->product(100, [[1, null, 100]]);
        $r = $this->book($p, [$ev => 10], null, 'accepted', ['plan' => 'full', 'accepted' => add_days(today_iso(), -10)]);
        $f = Finance::summary($r);
        $this->assertSame('Overdue', $f['status']); // full payment due 5 days after acceptance
        $this->assertSame(1250.0, $f['overdueAmount']);
        $this->assertSame('accepted', $r->fresh()->status);
    }

    public function test_plan_config_and_installment_rules(): void
    {
        $cfg = Finance::planConfig($this->s);
        $this->assertNull(Finance::validatePlanConfig($cfg));
        $bad = $cfg;
        $bad['standard']['downPct'] = 0;
        $this->assertStringContainsString('more than 0%', Finance::validatePlanConfig($bad));
        $bad['standard']['downPct'] = 110;
        $this->assertStringContainsString('at most 100%', Finance::validatePlanConfig($bad));
        $this->assertArrayNotHasKey('resPct', Finance::planConfig($this->s)['standard']);
        $none = array_map(fn ($c) => ['enabled' => false] + $c, $cfg);
        $this->assertSame('Enable at least one payment plan.', Finance::validatePlanConfig($none));

        $this->assertFalse(Finance::installmentEligibility($this->s, add_days(today_iso(), 30))['ok']);
        $this->assertTrue(Finance::installmentEligibility($this->s, add_months(today_iso(), 3))['ok']);

        $ev = add_months(today_iso(), 3);
        $items = Finance::suggestInstallments(700, 3, today_iso(), $ev);
        $this->assertSame(700.0, r2(array_sum(array_column($items, 'amount'))));
        $this->assertSame($ev, end($items)['due']);
        $this->assertNull(Finance::validateInstallments($items, 700, $ev, null, 4));
        $this->assertStringContainsString('must match exactly', Finance::validateInstallments($items, 800, $ev));
        $this->assertStringContainsString('at most 2', Finance::validateInstallments($items, 700, $ev, null, 2));
        $late = $items;
        $late[2]['due'] = add_days($ev, 1);
        $this->assertStringContainsString('after the event date', Finance::validateInstallments($late, 700, $ev));
    }

    /* ---------- payout formula (change list) ---------- */

    public function test_payout_is_items_minus_commission_plus_delivery_plus_voucher(): void
    {
        $ev = add_days(today_iso(), -2);
        $p = $this->product(100, [[1, null, 100]]);
        $r = $this->book($p, [$ev => 10], null, 'completed', ['plan' => 'full', 'accepted' => add_days($ev, -10)]);
        // pretend the order had delivery 350, a required service 250 and a ₱100 voucher
        $pricing = array_merge($r->pricing, ['deliveryFee' => 350.0, 'serviceFee' => 250.0, 'total' => 1600.0, 'voucher' => 100.0]);
        $r->update(['pricing' => $pricing]);
        Payment::create(['code' => 'TXN-P1', 'rental_request_id' => $r->id, 'type' => 'full', 'method' => 'bank', 'amount' => 1600, 'date' => today_iso(), 'status' => 'verified', 'submitted_at' => now()]);
        $b = Payouts::breakdown($r->fresh(), 10.0);
        $this->assertSame(1600.0, $b['income']);
        $this->assertSame(250.0, $b['service']);  // stays with the platform
        $this->assertSame(1000.0, $b['items']);   // 1600 − 350 delivery − 250 service
        $this->assertSame(100.0, $b['commission']);
        $this->assertSame(1000.0 - 100 + 350 + 100, $b['net']);
    }
}
