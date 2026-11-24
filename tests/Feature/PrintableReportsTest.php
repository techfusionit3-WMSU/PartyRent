<?php

namespace Tests\Feature;

use App\Domain\Finance;
use App\Domain\ReportCenter;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Printable Reports: supplier reports locked to the shop, admin consolidated reports, filters, print / PDF / CSV,
 * empty periods, access rules, and totals that match the database.
 */
class PrintableReportsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private User $juan;

    private Supplier $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->shop = $this->juan->supplier;
    }

    private function asShop(): static
    {
        return $this->actingAs($this->juan)->withSession(['mode' => 'supplier']);
    }

    private function asAdmin(): static
    {
        return $this->actingAs(User::where('email', 'admin@partyrent.ph')->firstOrFail())->withSession(['mode' => 'admin']);
    }

    /** Build a report directly (same engine as the pages). */
    private function report(array $q, ?Supplier $shop): array
    {
        return ReportCenter::build(ReportCenter::filters(Request::create('/x', 'GET', $q), $shop));
    }

    private function fig(array $report, string $label): float
    {
        foreach ($report['summary'] as [$l, $v]) {
            if ($l === $label) {
                return (float) $v;
            }
        }
        $this->fail("No summary figure '$label'");
    }

    public function test_daily_transactions_report(): void
    {
        $r = RentalRequest::where('supplier_id', $this->shop->id)->whereNotIn('status', ReportCenter::NOT_SALE)->firstOrFail();
        $r->forceFill(['created_at' => '2026-03-14 10:00:00'])->saveQuietly();
        RentalRequest::where('supplier_id', $this->shop->id)->whereKeyNot($r->id)->whereDate('created_at', '2026-03-14')->update(['created_at' => '2026-03-01 09:00:00']);

        $rep = $this->report(['type' => 'transactions', 'period' => 'day', 'date' => '2026-03-14'], $this->shop);
        $this->assertCount(1, $rep['tables'][0]['rows']);
        $m = ReportCenter::money($r);
        $this->assertEquals($m['charges'], $this->fig($rep, 'Charges'));
        $this->assertEquals($m['collected'], $this->fig($rep, 'Collected'));
        $this->assertEquals($m['balance'], $this->fig($rep, 'Still owed'));

        $this->asShop()->get(route('supplier.reports.center', ['type' => 'transactions', 'period' => 'day', 'date' => '2026-03-14']))
            ->assertOk()->assertSee('Rental transactions')->assertSee($r->code)->assertSee($this->shop->name)->assertSee(fmt_date('2026-03-14'))
            ->assertSee('Generate report')->assertSee('Reset filters');
    }

    public function test_monthly_sales_report_counts_collected_money_and_not_cancelled_rentals(): void
    {
        $month = substr(today_iso(), 0, 7);
        $ids = RentalRequest::where('supplier_id', $this->shop->id)->pluck('id');
        $pays = Payment::whereIn('rental_request_id', $ids)->where('status', 'verified')->where('date', 'like', $month.'%')->get();
        $expected = $pays->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $pays->where('type', 'refund')->sum('amount');

        $rep = $this->report(['type' => 'sales', 'period' => 'month', 'date' => today_iso()], $this->shop);
        $this->assertEqualsWithDelta($expected, $this->fig($rep, 'Net collected'), 0.01);

        // a cancelled rental starting this month is never a sale
        $held = RentalRequest::where('supplier_id', $this->shop->id)->whereNotIn('status', ReportCenter::NOT_SALE)->firstOrFail();
        $held->update(['start_date' => today_iso(), 'end_date' => today_iso()]);
        $before = $this->fig($this->report(['type' => 'sales', 'period' => 'month'], $this->shop), 'Confirmed rentals held');
        $held->update(['status' => 'cancelled']);
        $this->assertSame($before - 1, $this->fig($this->report(['type' => 'sales', 'period' => 'month'], $this->shop), 'Confirmed rentals held'));
    }

    public function test_payments_report_paid_and_pending(): void
    {
        $r = RentalRequest::where('supplier_id', $this->shop->id)->whereNotIn('status', ReportCenter::NOT_SALE)->firstOrFail();
        $d = '2026-02-10';
        Payment::where('date', 'like', '2026-02%')->delete();
        Payment::create(['code' => 'PAY-T1', 'rental_request_id' => $r->id, 'type' => 'balance', 'method' => 'cash', 'amount' => 1200, 'date' => $d, 'status' => 'verified', 'submitted_at' => now()]);
        Payment::create(['code' => 'PAY-T2', 'rental_request_id' => $r->id, 'type' => 'balance', 'method' => 'bank', 'amount' => 800, 'date' => $d, 'status' => 'pending', 'reference' => 'REF-T2', 'submitted_at' => now()]);
        Payment::create(['code' => 'PAY-T3', 'rental_request_id' => $r->id, 'type' => 'refund', 'method' => 'cash', 'amount' => 200, 'date' => $d, 'status' => 'verified', 'submitted_at' => now()]);

        $all = $this->report(['type' => 'payments', 'period' => 'month', 'date' => $d], $this->shop);
        $this->assertEquals(1200, $this->fig($all, 'Rent received'));
        $this->assertEquals(200, $this->fig($all, 'Refunds & deposits returned'));
        $this->assertEquals(1000, $this->fig($all, 'Net verified'));
        $this->assertEquals(800, $this->fig($all, 'Waiting for verification'));
        $this->assertCount(3, $all['tables'][0]['rows']);

        $paid = $this->report(['type' => 'payments', 'period' => 'month', 'date' => $d, 'pstatus' => 'verified'], $this->shop);
        $this->assertCount(2, $paid['tables'][0]['rows']);
        $pending = $this->report(['type' => 'payments', 'period' => 'month', 'date' => $d, 'pstatus' => 'pending'], $this->shop);
        $this->assertCount(1, $pending['tables'][0]['rows']);
        $this->assertEquals(0, $this->fig($pending, 'Net verified'));
    }

    public function test_inventory_report_matches_stock(): void
    {
        $prods = Product::where('supplier_id', $this->shop->id)->where('status', '!=', 'deleted')->get();
        $rep = $this->report(['type' => 'inventory'], $this->shop);
        $this->assertCount($prods->count(), $rep['tables'][0]['rows']);
        $this->assertEquals($prods->sum('total_stock'), $this->fig($rep, 'Units in stock'));
        $this->assertEquals($prods->sum('damaged'), $this->fig($rep, 'Damaged'));
        $this->assertStringStartsWith('As of', ReportCenter::periodText(ReportCenter::filters(Request::create('/x', 'GET', ['type' => 'inventory']), $this->shop)));
    }

    public function test_damage_report(): void
    {
        $r = RentalRequest::whereNotNull('inspection')->get()->first(fn ($x) => ($x->inspection['compensation'] ?? 0) > 0);
        $this->assertNotNull($r, 'demo data has a damaged rental');
        $shop = $r->supplier;
        $on = $r->inspection['returnDate'];
        $rep = $this->report(['type' => 'damage', 'period' => 'custom', 'from' => $on, 'to' => $on], $shop);
        $expected = RentalRequest::where('supplier_id', $shop->id)->whereNotNull('inspection')->get()->filter(fn ($x) => ($x->inspection['returnDate'] ?? null) === $on)->sum(fn ($x) => $x->inspection['compensation'] ?? 0);
        $this->assertEquals($expected, $this->fig($rep, 'Charged'));
        $this->assertContains($r->code, array_column($rep['tables'][1]['rows'], 0));
    }

    public function test_branch_comparison_adds_up(): void
    {
        $q = ['period' => 'year', 'date' => today_iso()];
        $branches = $this->report($q + ['type' => 'branches'], $this->shop);
        $tx = $this->report($q + ['type' => 'transactions'], $this->shop);
        $t = $branches['tables'][0];
        $this->assertGreaterThanOrEqual(InventoryLocation::where('supplier_id', $this->shop->id)->count(), count($t['rows']));
        $col = array_search('Their charges', array_column($t['cols'], 0), true);
        $this->assertEqualsWithDelta($this->fig($tx, 'Charges'), $t['total'][$col], 0.01);
    }

    public function test_admin_consolidated_report_covers_every_supplier(): void
    {
        $q = ['type' => 'transactions', 'period' => 'custom', 'from' => '2020-01-01', 'to' => '2030-12-31'];
        $rep = $this->report($q, null);
        $this->assertCount(RentalRequest::count(), $rep['tables'][0]['rows']);
        $this->assertSame('Supplier', $rep['tables'][0]['cols'][3][0]);
        // totals match the database: confirmed rentals' charges, and collected money from verified payments
        $live = RentalRequest::whereNotIn('status', ReportCenter::NOT_SALE)->get();
        $this->assertEqualsWithDelta($live->sum(fn ($r) => $r->fin()['payable']), $this->fig($rep, 'Charges'), 0.01);
        $ver = Payment::whereIn('rental_request_id', $live->pluck('id'))->where('status', 'verified')->get();
        $this->assertEqualsWithDelta($ver->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $ver->where('type', 'refund')->sum('amount'), $this->fig($rep, 'Collected'), 0.01);

        $this->asAdmin()->get(route('admin.reports.center', $q))->assertOk()->assertSee('All suppliers')->assertSee($this->shop->name);
        $this->get(route('admin.reports.center', ['type' => 'suppliers']))->assertOk()->assertSee('Supplier performance')->assertSee('Commission');
    }

    public function test_admin_filters_by_supplier_and_branch(): void
    {
        $other = Supplier::approved()->whereKeyNot($this->shop->id)->whereIn('id', RentalRequest::pluck('supplier_id'))->firstOrFail();
        $q = ['type' => 'transactions', 'period' => 'custom', 'from' => '2020-01-01', 'to' => '2030-12-31'];
        $rep = $this->report($q + ['supplier' => $this->shop->id], null);
        $this->assertSame(RentalRequest::where('supplier_id', $this->shop->id)->pluck('code')->sort()->values()->all(), collect($rep['tables'][0]['rows'])->pluck(0)->sort()->values()->all());

        $branch = InventoryLocation::where('supplier_id', $this->shop->id)->firstOrFail();
        $byBranch = $this->report($q + ['supplier' => $this->shop->id, 'branch' => $branch->id], null);
        $this->assertSame(RentalRequest::where('branch_id', $branch->id)->count(), count($byBranch['tables'][0]['rows']));

        // a branch must belong to the chosen supplier
        $this->asAdmin()->get(route('admin.reports.center', $q + ['supplier' => $other->id, 'branch' => $branch->id]))->assertNotFound();
        $this->get(route('admin.reports.center', $q + ['branch' => $branch->id]))->assertNotFound();
        $this->get(route('admin.reports.center', $q + ['supplier' => $this->shop->id, 'branch' => $branch->id]))->assertOk()->assertSee('Branch: '.$branch->name);
    }

    public function test_print_and_pdf_layouts(): void
    {
        $q = ['type' => 'sales', 'period' => 'month'];
        $this->asShop()->get(route('supplier.reports.print', $q))->assertOk()
            ->assertSee('Sales &amp; revenue', false)->assertSee($this->shop->name)->assertSee('size: A4', false)->assertSee('counter(page)', false)
            ->assertSee('Generated')->assertSee('window.print()', false)->assertDontSee('Generate report')->assertDontSee('Business Reports');
        $this->get(route('supplier.reports.print', $q + ['pdf' => 1]))->assertOk()->assertSee('Save as PDF');
    }

    public function test_csv_export(): void
    {
        $r = RentalRequest::where('supplier_id', $this->shop->id)->firstOrFail();
        $res = $this->asShop()->get(route('supplier.reports.export', ['type' => 'transactions', 'period' => 'custom', 'from' => '2020-01-01', 'to' => '2030-12-31']));
        $res->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $res->streamedContent();
        $this->assertStringContainsString('Rental transactions', $csv);
        $this->assertStringContainsString($this->shop->name, $csv);
        $this->assertStringContainsString($r->code, $csv);
        $this->assertStringContainsString('Total (confirmed)', $csv);
        $this->assertStringNotContainsString('@', $csv); // no customer e-mails or other contact details
    }

    public function test_empty_period(): void
    {
        $rep = $this->report(['type' => 'transactions', 'period' => 'day', 'date' => '2001-01-01'], $this->shop);
        $this->assertSame([], $rep['tables'][0]['rows']);
        $this->assertNull($rep['tables'][0]['total']);
        $this->assertEquals(0, $this->fig($rep, 'Charges'));
        $this->asShop()->get(route('supplier.reports.center', ['type' => 'transactions', 'period' => 'day', 'date' => '2001-01-01']))->assertOk()->assertSee('No rentals were booked in this period.');
        $this->get(route('supplier.reports.export', ['type' => 'damage', 'period' => 'day', 'date' => '2001-01-01']))->assertOk();
    }

    public function test_access_is_limited_to_the_right_people(): void
    {
        $other = Supplier::approved()->whereKeyNot($this->shop->id)->firstOrFail();
        $otherBranch = InventoryLocation::where('supplier_id', $other->id)->firstOrFail();
        $otherItem = Product::where('supplier_id', $other->id)->firstOrFail();

        // a supplier sees only their own shop: another shop's branch or item is refused; a supplier id is ignored
        $this->asShop();
        $this->get(route('supplier.reports.center', ['type' => 'transactions', 'branch' => $otherBranch->id]))->assertNotFound();
        $this->get(route('supplier.reports.center', ['type' => 'inventory', 'item' => 'p:'.$otherItem->id]))->assertNotFound();
        $rep = $this->report(['type' => 'inventory', 'supplier' => $other->id], $this->shop);
        $this->assertCount(Product::where('supplier_id', $this->shop->id)->where('status', '!=', 'deleted')->count(), $rep['tables'][0]['rows']);
        $this->get(route('supplier.reports.center', ['type' => 'suppliers']))->assertOk()->assertSee('Rental transactions')->assertDontSee('Supplier performance</option>', false);
        $this->assertNotEquals(200, $this->get(route('admin.reports.center'))->getStatusCode());

        // a customer cannot open either
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $res = $this->actingAs($maria)->withSession(['mode' => 'customer'])->get(route('supplier.reports.center'));
        $this->assertNotEquals(200, $res->getStatusCode());
        $this->assertNotEquals(200, $this->get(route('admin.reports.export'))->getStatusCode());

        // shop team: the cashier (reports permission) can, the crew cannot
        // (a shop cashier opens them from the cashier area, locked to their shop; another shop's branch is refused)
        $grace = User::where('email', 'cashier@elegantoccasion.ph')->firstOrFail();
        $eo = $grace->staffOf->supplier;
        $rep = $this->actingAs($grace)->get(route('cashier.reports.center', ['type' => 'transactions', 'period' => 'custom', 'from' => '2020-01-01', 'to' => '2030-12-31']))->assertOk()->assertSee($eo->name);
        foreach (RentalRequest::where('supplier_id', '!=', $eo->id)->pluck('code') as $code) {
            $rep->assertDontSee($code);
        }
        $this->get(route('cashier.reports.center', ['type' => 'transactions', 'branch' => $otherBranch->id]))->assertNotFound();
        $this->get(route('cashier.reports.export', ['type' => 'suppliers']))->assertOk()->assertDontSee('Supplier performance');
        $crew = $this->actingAs(User::where('email', 'staff@elegantoccasion.ph')->firstOrFail())->withSession(['mode' => 'supplier'])->get(route('supplier.reports.export'));
        $this->assertNotEquals(200, $crew->getStatusCode());
        $this->assertNotEquals(200, $this->get(route('cashier.reports.export'))->getStatusCode());
    }
}
