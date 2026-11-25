<?php

namespace Tests\Feature;

use App\Domain\Branches;
use App\Domain\ShopStaffs;
use App\Models\AuditLog;
use App\Models\CartLine;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\RentalTransfer;
use App\Models\ShopStaff;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BranchScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-branch, part 2: where the customer is (service areas, stock near you, nearest branch at checkout), combining
 * nearby branches with stock transfers, branch-level staff and Branch Admins, the branch switcher and branch reports.
 * Demo: Elegant Occasion (Liza) has Main Warehouse (100 Gold Chiavari Chairs), Branch A – Pasig (50) and
 * Branch B – Makati (0), all in Metro Manila; Maria lives in Quezon City.
 */
class MultiBranchAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $eo;

    private Product $chair;

    private InventoryLocation $main;

    private InventoryLocation $pasig;

    private InventoryLocation $makati;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eo = Supplier::where('name', 'Elegant Occasion Rentals')->firstOrFail();
        $this->chair = Product::where('supplier_id', $this->eo->id)->where('name', 'Gold Chiavari Chair')->firstOrFail();
        [$this->main, $this->pasig, $this->makati] = $this->eo->branches()->get()->all();
        inv()->flush();
    }

    private function day(): string
    {
        return add_days(today_iso(), 40);
    }

    private function maria(): User
    {
        return User::where('email', 'maria@demo.ph')->firstOrFail();
    }

    /** Maria puts $qty chairs in her list and checks out with pickup. */
    private function rentChairs(int $qty, array $form = [])
    {
        $m = $this->maria();
        CartLine::where('user_id', $m->id)->delete();
        $this->actingAs($m)->withSession(['mode' => 'customer']);
        $this->post('/cart', ['type' => 'item', 'ref_id' => $this->chair->id, 'days' => json_encode([$this->day() => $qty])]);

        return $this->checkout($this->eo->id, $form + ['contactName' => 'Maria', 'contactPhone' => '0917 555 0101', 'fulfillment' => 'pickup']);
    }

    private function staff(string $email, array $roles, ?array $branches): User
    {
        $u = User::where('email', $email)->firstOrFail();
        ShopStaff::where('user_id', $u->id)->update(['role' => $roles[0], 'roles' => json_encode($roles), 'branch_ids' => $branches === null ? null : json_encode($branches)]);

        return $u->fresh();
    }

    public function test_customer_location_limits_branches_and_stock_near_them(): void
    {
        $this->actingAs($this->maria());
        // Quezon City: every Metro Manila branch serves her; the chairs near her = the best single branch (100)
        $this->assertSame(100, Branches::availableNear($this->eo, 'item', $this->chair->id, $this->day()));
        $this->get(route('products.show', $this->chair))->assertOk()->assertSee('available near')->assertSee('Showing shops near');

        // she now rents in Davao City: no Elegant branch serves her
        $this->post(route('near.store'), ['near' => ['cityCode' => '112402000']])->assertRedirect();
        $this->assertStringStartsWith('Davao City', \App\Support\Near::get()['label']);
        $this->assertSame(0, Branches::availableNear($this->eo, 'item', $this->chair->id, $this->day()));
        $opts = Branches::options($this->eo, [['id' => 'x', 'type' => 'item', 'ref_id' => $this->chair->id, 'days' => [$this->day() => 5]]], 'pickup');
        $this->assertFalse(collect($opts)->contains(fn ($o) => $o['deliverable']));
        $this->assertStringContainsString('Outside the area', $opts[0]['note']);
        $this->getJson(route('api.calendar', ['kind' => 'item', 'id' => $this->chair->id, 'month' => substr($this->day(), 0, 7)]))
            ->assertOk()->assertJsonPath('days.'.((int) substr($this->day(), 8) - 1).'.available', 0);
        $res = $this->rentChairs(5);
        $this->assertFalse((bool) $res->json('ok'));
        $this->assertStringContainsString('Outside the area', json_encode($res->json()));
    }

    public function test_only_shops_and_items_that_serve_the_customer_are_listed(): void
    {
        $this->actingAs($this->maria())->withSession(['mode' => 'customer']);
        // Quezon City: Metro Manila shops are listed
        $this->get(route('home', ['q' => 'Chiavari']))->assertOk()->assertSee('Gold Chiavari Chair');
        $this->get(route('suppliers.index'))->assertOk()->assertSee('Elegant Occasion Rentals');
        // Davao City: no demo shop serves it, so none of their shops, items or packages are listed
        $this->post(route('near.store'), ['near' => ['cityCode' => '112402000']]);
        $this->assertNotContains($this->eo->id, Branches::supplierIdsNear());
        $this->get(route('home', ['q' => 'Chiavari']))->assertOk()->assertDontSee('Gold Chiavari Chair');
        $this->get(route('suppliers.index'))->assertOk()->assertDontSee('Elegant Occasion Rentals')->assertSee('No shop near');
        $this->get(route('packages.index'))->assertOk()->assertDontSee('Elegant Occasion Rentals');
        // an old link still opens, and says the shop does not serve them
        $this->get(route('products.show', $this->chair))->assertOk()->assertSee('This shop does not serve');
        $this->get(route('suppliers.show', $this->eo))->assertOk()->assertSee('This shop does not serve');
        // a branch that reaches Davao brings the shop back
        $this->makati->update(['lat' => 7.07, 'lng' => 125.61, 'service_km' => 50]);
        $this->assertContains($this->eo->id, Branches::supplierIdsNear());
        $this->get(route('suppliers.index'))->assertOk()->assertSee('Elegant Occasion Rentals');
        // no place known (guest): everything, as before
        auth()->logout();
        session()->forget('near');
        $this->assertNull(Branches::supplierIdsNear());
    }

    public function test_pickup_goes_to_the_nearest_branch_with_stock(): void
    {
        $m = $this->maria();
        $m->forceFill(['near' => ['city' => '137403000', 'label' => 'Pasig City', 'lat' => 14.585, 'lng' => 121.09, 'from' => 'chosen']])->save();
        $this->assertTrue($this->rentChairs(20)->json('ok') ?? false, 'checkout ok');
        $r = RentalRequest::where('customer_id', $m->id)->latest('id')->first();
        $this->assertSame($this->pasig->id, $r->branch_id); // Pasig is next door and has 50
    }

    public function test_no_single_branch_and_combining_off_means_no_split(): void
    {
        $this->assertFalse((bool) $this->rentChairs(130)->json('ok'));
        $this->assertSame(0, RentalRequest::where('customer_id', $this->maria()->id)->where('created_at', '>=', now()->subMinute())->count());
    }

    public function test_combined_rental_borrows_from_a_nearby_branch_and_records_transfers(): void
    {
        $this->eo->update(['branch_settings' => ['serviceKm' => 50, 'combine' => true, 'combineKm' => 30]]);
        $res = $this->rentChairs(130);
        $this->assertTrue((bool) $res->json('ok'), json_encode($res->json()));
        $r = RentalRequest::where('customer_id', $this->maria()->id)->latest('id')->firstOrFail();
        $this->assertSame($this->main->id, $r->branch_id); // the branch with the most serves the customer
        $this->assertTrue(Branches::isCombined($r));
        $this->assertSame([$this->chair->id => [$this->pasig->id => 30]], Branches::borrowed($r));
        // the same price as one branch: 130 chairs, no extra fee
        $this->assertEquals(0, $r->pricing['deliveryFee'] ?? 0);
        // once it holds stock (down payment verified), the 30 are booked at Pasig for the date; Main is fully used
        $r->update(['status' => 'ongoing', 'reserved_at' => now()]);
        inv()->flush();
        $this->assertSame(20, inv()->availableAtLocation($this->chair->id, $this->pasig->id, $this->day()));
        $t = RentalTransfer::where('rental_request_id', $r->id)->firstOrFail();
        $this->assertSame(['planned', 30, $this->pasig->id, $this->main->id], [$t->status, $t->qty, $t->from_location_id, $t->to_location_id]);
        // customer sees one rental, with a short note
        $this->get(route('rentals.show', $r))->assertOk()->assertSee('brought in from another');

        // Pasig's crew sends, Main's crew receives; the wrong branch cannot do the other's step
        $pasigCrew = $this->staff('staff@elegantoccasion.ph', ['crew'], [$this->pasig->id]);
        $mainInv = $this->staff('inventory@elegantoccasion.ph', ['inventory'], [$this->main->id]);
        $this->actingAs($mainInv)->withSession(['mode' => 'supplier'])->post(route('supplier.transfers.step', [$t, 'send']))->assertSessionHas('error');
        $this->actingAs($pasigCrew)->withSession(['mode' => 'supplier'])->get(route('supplier.requests.show', $r))->assertOk()->assertSee('Your branch lends stock');
        $this->post(route('supplier.transfers.step', [$t, 'send']))->assertSessionHas('success');
        $this->post(route('supplier.requests.accept', $r))->assertNotFound(); // a lending branch only sends
        $this->actingAs($mainInv)->post(route('supplier.transfers.step', [$t, 'receive']))->assertSessionHas('success');
        $this->post(route('supplier.transfers.step', [$t, 'keep']))->assertSessionHas('error'); // not before the rental ends
        $this->get(route('supplier.transfers.slip', $t))->assertOk()->assertSee('Received by')->assertSee('Released by');

        // after the rental Main keeps them: 30 chairs move from Pasig's stock to Main's
        $r->update(['status' => 'completed', 'completed_at' => now()]);
        $this->actingAs($this->eo->owner)->post(route('supplier.transfers.step', [$t, 'keep']))->assertSessionHas('success');
        inv()->flush();
        $this->assertSame([130, 20], [inv()->stockAt($this->chair->id, $this->main->id), inv()->stockAt($this->chair->id, $this->pasig->id)]);
        $this->assertSame('kept', $t->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Kept borrowed stock at the home branch']);
        $this->assertGreaterThanOrEqual(3, AuditLog::where('details', 'like', '%'.$r->code.'%')->count());
    }

    public function test_a_lending_branch_that_closes_is_flagged_and_the_rental_can_be_replanned(): void
    {
        $this->eo->update(['branch_settings' => ['serviceKm' => 50, 'combine' => true, 'combineKm' => 30]]);
        $this->assertTrue((bool) $this->rentChairs(130)->json('ok'));
        $r = RentalRequest::where('customer_id', $this->maria()->id)->latest('id')->firstOrFail();
        $this->pasig->update(['active' => false]);
        $this->assertNotEmpty(\App\Domain\Transfers::problems($r->fresh()));
        $this->actingAs($this->eo->owner)->withSession(['mode' => 'supplier'])->get(route('supplier.requests.show', $r))->assertOk()->assertSee('Re-plan branches');
        // Pasig was the only lender: nothing else nearby has 30 chairs, so re-planning says so
        $this->post(route('supplier.requests.replan', $r))->assertSessionHas('error');
        // the customer lowers to 100: Main alone covers it and the loan is cancelled
        $lines = $r->lines;
        $lines[0]['days'] = [$this->day() => 100];
        $r->update(['lines' => $lines]);
        $this->post(route('supplier.requests.replan', $r))->assertSessionHas('success');
        $this->assertFalse(Branches::isCombined($r->fresh()));
        $this->assertSame('cancelled', RentalTransfer::where('rental_request_id', $r->id)->first()->status);
    }

    public function test_branch_staff_only_see_and_act_on_their_branch(): void
    {
        $mainRental = RentalRequest::where('supplier_id', $this->eo->id)->where('branch_id', $this->main->id)->firstOrFail();
        $pasigRental = $mainRental->replicate();
        $pasigRental->forceFill(['code' => 'PR-9901', 'status' => 'pending', 'branch_id' => $this->pasig->id, 'lines' => Branches::onBranch($mainRental->lines, $this->pasig->id)])->saveQuietly();
        $desk = $this->staff('desk@elegantoccasion.ph', ['desk'], [$this->pasig->id]);
        $this->actingAs($desk)->withSession(['mode' => 'supplier']);
        $this->assertSame([$this->pasig->id], BranchScope::allowed($desk));
        $this->assertSame($this->pasig->id, BranchScope::current($desk)?->id); // locked to their only branch

        $this->get(route('supplier.requests', ['tab' => 'all']))->assertOk()->assertSee($pasigRental->code)->assertDontSee($mainRental->code)
            ->assertSee('Your branch:')->assertSee('Locked');
        $this->get(route('supplier.requests.show', $mainRental))->assertNotFound();
        $this->post(route('supplier.requests.accept', $mainRental))->assertNotFound();
        $this->get(route('supplier.requests', ['tab' => 'all', 'branch' => $this->main->id]))->assertOk()->assertDontSee($mainRental->code);
        // the switcher cannot be moved to another branch
        $this->post(route('branch-view'), ['branch' => $this->main->id])->assertSessionHas('error');
        // organization pages are closed to branch staff
        $this->get(route('supplier.locations.create'))->assertRedirect();
        $this->post(route('supplier.locations.settings'), ['serviceKm' => 10, 'combineKm' => 5])->assertForbidden();

        // a cashier limited to Pasig: another branch's payment is out of reach
        $cashier = $this->staff('cashier@elegantoccasion.ph', ['cashier'], [$this->pasig->id]);
        $pay = Payment::create(['code' => 'PAY-BR1', 'rental_request_id' => $mainRental->id, 'type' => 'balance', 'method' => 'cash', 'amount' => 100, 'date' => today_iso(), 'status' => 'pending', 'submitted_at' => now()]);
        $this->actingAs($cashier)->post(route('cashier.verify', $pay))->assertNotFound();
    }

    public function test_owner_switches_the_dashboard_to_one_branch(): void
    {
        $owner = $this->eo->owner;
        $mainRental = RentalRequest::where('supplier_id', $this->eo->id)->where('branch_id', $this->main->id)->firstOrFail();
        $this->actingAs($owner)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.requests', ['tab' => 'all']))->assertOk()->assertSee('All branches (whole organization)')->assertSee($mainRental->code);
        $this->post(route('branch-view'), ['branch' => $this->makati->id])->assertSessionHas('success');
        $this->get(route('supplier.requests', ['tab' => 'all']))->assertOk()->assertDontSee($mainRental->code)->assertSee('Makati only');
        $this->post(route('branch-view'), ['branch' => ''])->assertSessionHas('success');
        $this->get(route('supplier.requests', ['tab' => 'all']))->assertSee($mainRental->code);
        // another shop's branch is refused
        $other = InventoryLocation::where('supplier_id', '!=', $this->eo->id)->firstOrFail();
        $this->post(route('branch-view'), ['branch' => $other->id])->assertSessionHas('error');
    }

    public function test_reports_follow_the_branch_and_org_wide_is_for_the_organization_level(): void
    {
        $q = ['type' => 'transactions', 'period' => 'custom', 'from' => '2020-01-01', 'to' => '2030-12-31'];
        $all = RentalRequest::where('supplier_id', $this->eo->id)->count();
        $atMain = RentalRequest::where('supplier_id', $this->eo->id)->where('branch_id', $this->main->id)->count();
        // owner: whole organization by default ("all"), one branch when asked
        $this->actingAs($this->eo->owner)->withSession(['mode' => 'supplier'])
            ->get(route('supplier.reports.center', $q + ['branch' => 'all']))->assertOk()->assertSee('All branches (whole organization)');
        $f = \App\Domain\ReportCenter::filters(\Illuminate\Http\Request::create('/x', 'GET', $q + ['branch' => 'all']), $this->eo);
        $this->assertCount($all, \App\Domain\ReportCenter::build($f)['tables'][0]['rows']);

        // a Branch Admin of Main: only Main, never the whole organization, another branch is refused
        $ba = $this->staff('desk@elegantoccasion.ph', ['branch_admin'], [$this->main->id]);
        $this->actingAs($ba);
        $f = \App\Domain\ReportCenter::filters(\Illuminate\Http\Request::create('/x', 'GET', $q + ['branch' => 'all']), $this->eo);
        $this->assertSame($this->main->id, $f['branch']?->id);
        $this->assertCount($atMain, \App\Domain\ReportCenter::build($f)['tables'][0]['rows']);
        $this->get(route('supplier.reports.center', $q + ['branch' => $this->pasig->id]))->assertNotFound();
        $this->get(route('supplier.reports.center', $q))->assertOk()->assertDontSee('whole organization');
    }

    public function test_branch_admin_runs_their_branch_team_only(): void
    {
        $ba = $this->staff('desk@elegantoccasion.ph', ['branch_admin'], [$this->pasig->id]);
        $scope = ShopStaffs::managerScope($this->eo, $ba);
        $this->assertFalse($scope['owner']);
        $this->assertNotContains('org_manager', $scope['roles']);
        $this->assertNotContains('branch_admin', $scope['roles']);
        $this->actingAs($ba)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.team'))->assertOk()->assertDontSee('cashier@elegantoccasion.ph'); // not their branch
        // invite: only branch roles, only their branch
        $this->assertFalse(ShopStaffs::invite($this->eo, $ba, ['email' => 'new1@example.com', 'roles' => ['org_manager']])['ok']);
        $this->assertFalse(ShopStaffs::invite($this->eo, $ba, ['email' => 'new2@example.com', 'roles' => ['crew'], 'branches' => [$this->main->id]])['ok']);
        $ok = ShopStaffs::invite($this->eo, $ba, ['email' => 'new3@example.com', 'roles' => ['crew']]);
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame([$this->pasig->id], $ok['invite']->fresh()->branch_ids);
        // a member of another branch, and single permissions, are out of reach
        $cashier = $this->staff('cashier@elegantoccasion.ph', ['cashier'], [$this->main->id]);
        $this->assertFalse(ShopStaffs::update($this->eo, $ba, $cashier, ['status' => 'deactivated'])['ok']);
        $crew = $this->staff('staff@elegantoccasion.ph', ['crew'], [$this->pasig->id]);
        $this->assertFalse(ShopStaffs::update($this->eo, $ba, $crew, ['perms_set' => 1, 'perms' => ['shop.manage']])['ok']);
        $this->assertTrue(ShopStaffs::update($this->eo, $ba, $crew, ['roles' => ['crew', 'desk']])['ok']);

        // the owner: an Organization Admin covers every branch; a Branch Admin needs a branch
        $owner = $this->eo->owner;
        $this->assertTrue(ShopStaffs::update($this->eo, $owner, $cashier, ['roles' => ['org_manager'], 'branches_set' => 1, 'branches' => [$this->main->id]])['ok']);
        $this->assertNull($cashier->fresh()->staffOf->branch_ids);
        $this->assertNull($cashier->fresh()->branchIds());
        $this->assertFalse(ShopStaffs::update($this->eo, $owner, $crew, ['roles' => ['branch_admin'], 'branches_set' => 1, 'branches' => []])['ok']);
        // an Organization Admin manages branch admins but not other Organization Admins
        $ba2 = $this->staff('inventory@elegantoccasion.ph', ['org_manager'], null);
        $this->assertTrue(ShopStaffs::canManage(ShopStaffs::managerScope($this->eo, $ba2), $ba->fresh()->staffOf));
        $this->assertFalse(ShopStaffs::canManage(ShopStaffs::managerScope($this->eo, $ba2), $cashier->fresh()->staffOf));
    }
}
