<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every page renders for the roles allowed to see it, and is refused for the rest. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function as(string $email, ?string $mode = null): static
    {
        $u = User::where('email', $email)->firstOrFail();

        return $this->actingAs($u)->withSession(['mode' => $mode ?? $u->defaultMode()]);
    }

    public function test_public_pages_render(): void
    {
        $p = \App\Models\Product::first();
        $k = \App\Models\Package::first();
        $s = Supplier::approved()->first();
        foreach (['/', '/browse', '/browse?view=packages', '/browse?q=pink+chairs&start='.add_days(today_iso(), 3).'&avail=1', '/suppliers', "/suppliers/{$s->id}", "/suppliers/{$s->id}?tab=policies", "/suppliers/{$s->id}?tab=reviews",
            "/products/{$p->id}", '/packages', "/packages/{$k->id}", '/ai', '/ai?q='.urlencode('outdoor birthday for 50 guests with tent and pink balloons'), '/login', '/register', '/forgot-password'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->getJson('/api/calendar?kind=item&id='.$p->id.'&month='.substr(today_iso(), 0, 7))->assertOk()->assertJsonStructure(['days']);
        $this->postJson('/api/schedule', ['type' => 'item', 'ref_id' => $p->id, 'days' => [add_days(today_iso(), 5) => 3]])->assertOk()->assertJsonPath('ok', true);
        $this->postJson('/ai/chat', ['message' => 'tiffany chair prices'])->assertOk()->assertJsonStructure(['html', 'chips']);
    }

    public function test_customer_pages_render(): void
    {
        $r = RentalRequest::where('code', 'PR-0001')->first();
        $this->as('maria@demo.ph');
        foreach (['/', '/cart', '/cart?compare=1', '/rentals', '/rentals?tab=active', '/rentals/PR-0001', '/rentals/PR-0006', '/my-payments', '/profile', '/start-selling', '/notifications', '/account', '/checkout/'.$r->supplier_id] as $url) {
            $this->get($url)->assertOk();
        }
        // another customer's rental is not visible
        $this->get('/rentals/PR-0008')->assertNotFound();
        // staff pages are not reachable
        $this->get('/cashier')->assertRedirect();
        $this->get('/admin')->assertRedirect();
    }

    public function test_supplier_pages_render(): void
    {
        $this->as('juan@demo.ph', 'supplier');
        $pid = \App\Models\Product::where('supplier_id', Supplier::where('name', 'like', 'Happy%')->value('id'))->value('id');
        $kid = \App\Models\Package::where('supplier_id', Supplier::where('name', 'like', 'Happy%')->value('id'))->value('id');
        foreach (['/supplier', '/supplier/requests', '/supplier/requests?tab=all', '/supplier/requests/PR-0001', '/supplier/requests/PR-0003', '/supplier/requests/PR-0011',
            '/supplier/schedule', '/supplier/payments', '/supplier/payments?tab=overdue', '/supplier/products', '/supplier/products/new', "/supplier/products/$pid/edit",
            '/supplier/packages', '/supplier/packages/new', "/supplier/packages/$kid/edit", '/supplier/inventory', '/supplier/shop', '/notifications',
            '/supplier/calendar', "/supplier/calendar?item=$pid&view=week", "/supplier/calendar?item=$pid&view=day", '/supplier/damages',
            '/supplier/reports', '/supplier/reports?range=year', '/supplier/reports.csv'] as $url) {
            $this->get($url)->assertOk();
        }
        // other shops' requests are hidden
        $this->get('/supplier/requests/PR-0005')->assertNotFound();
    }

    public function test_pending_supplier_cannot_open_supplier_mode(): void
    {
        $this->as('bea@demo.ph', 'supplier')->get('/supplier')->assertRedirect(route('start-selling'));
    }

    public function test_cashier_and_admin_pages_render(): void
    {
        $this->as('cashier@partyrent.ph');
        foreach (['/cashier', '/cashier/verification', '/cashier/record', '/cashier/record?request=PR-0001', '/cashier/balances', '/cashier/balances?tab=overdue', '/cashier/history', '/cashier/history?q=PR-0001', '/payments/TXN-10001/receipt', '/cashier/reports', '/cashier/reports?range=3m', '/cashier/reports.csv'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/admin')->assertRedirect();

        $this->as('admin@partyrent.ph');
        foreach (['/admin', '/admin/applications', '/admin/applications?tab=approved', '/admin/users', '/admin/transactions', '/admin/audit',
            '/admin/listings', '/admin/listings?view=packages', '/admin/categories', '/admin/complaints', '/admin/complaints?tab=all',
            '/admin/reports', '/admin/reports?range=year', '/admin/reports.csv'] as $url) {
            $this->get($url)->assertOk();
        }
        // restricted admin can't review applications
        $this->as('paolo.admin@partyrent.ph')->get('/admin/applications')->assertForbidden();

        $this->as('super@partyrent.ph');
        foreach (['/superadmin', '/superadmin/monitoring', '/superadmin/users', '/superadmin/users?role=cashier', '/superadmin/admins', '/superadmin/roles', '/superadmin/settings',
            '/admin/applications', '/admin/listings', '/admin/complaints', '/admin/reports', '/admin/audit', '/cashier/verification'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_moderation_actions(): void
    {
        $this->as('admin@partyrent.ph');
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();

        // log an issue on behalf of a customer
        $this->post('/admin/complaints', ['reporter_id' => $maria->id, 'request_code' => 'PR-0001', 'subject' => 'Chairs arrived scratched (test)', 'body' => 'Arrived two hours late.', 'priority' => 'high'])
            ->assertRedirect(route('admin.complaints', ['tab' => 'open']));
        $c = \App\Models\Complaint::where('subject', 'Chairs arrived scratched (test)')->firstOrFail();
        $this->assertSame('open', $c->status);
        $this->assertNotNull($c->rental_request_id);
        $this->post('/admin/complaints', ['reporter_id' => $maria->id, 'request_code' => 'PR-9999', 'subject' => 'x', 'body' => 'y', 'priority' => 'low'])->assertSessionHasErrors('request_code');

        // update it: customer gets notified
        $this->post("/admin/complaints/{$c->id}", ['status' => 'resolved', 'note' => 'Refunded the delivery fee.'])->assertSessionHas('success');
        $this->assertSame('resolved', $c->fresh()->status);
        $this->assertCount(1, $c->fresh()->updates);

        // hide and restore a listing
        $p = \App\Models\Product::where('status', 'published')->firstOrFail();
        $this->post("/admin/listings/{$p->id}/status", ['status' => 'hidden', 'reason' => 'Blurry photos'])->assertSessionHas('success');
        $this->assertSame('hidden', $p->fresh()->status);
        $this->post("/admin/listings/{$p->id}/status", ['status' => 'published'])->assertSessionHas('success');

        // sidebar hides pages the admin can't open
        $this->as('paolo.admin@partyrent.ph')->get('/admin')->assertOk()->assertDontSee('Supplier Applications');
    }

    public function test_superadmin_actions(): void
    {
        // only superadmins get in
        $this->as('admin@partyrent.ph')->get('/superadmin/settings')->assertRedirect();

        $this->as('super@partyrent.ph');
        $this->post('/superadmin/admins', ['name' => 'Nina Reyes', 'email' => 'nina.cashier@partyrent.ph', 'role' => 'cashier', 'password' => 'Temp-pass-2026'])->assertSessionHas('success');
        $nina = User::where('email', 'nina.cashier@partyrent.ph')->firstOrFail();
        $this->assertTrue($nina->hasRole('cashier'));

        // per-user permissions, then back to role defaults
        $this->post("/superadmin/admins/{$nina->id}", ['perms' => ['payments.view']])->assertSessionHas('success');
        $this->assertFalse($nina->fresh()->hasPerm('reports.view'));
        $this->post("/superadmin/admins/{$nina->id}", ['use_defaults' => 1])->assertSessionHas('success');
        $this->assertNull($nina->fresh()->cashier_perms);

        // default role permissions
        $this->post('/superadmin/roles', ['admin' => ['customers.view'], 'cashier' => ['payments.view', 'payments.verify']])->assertSessionHas('success');
        $this->assertFalse(User::where('email', 'admin@partyrent.ph')->first()->hasPerm('reports.view'));

        // settings
        $this->post('/superadmin/settings', ['platformName' => 'PartyRent PH', 'supportEmail' => 'help@partyrent.ph', 'supportPhone' => '0917 000 0000', 'overdueGraceDays' => 2, 'maxRentalDays' => 14, 'commissionPct' => 7, 'aiEnabled' => 1])->assertSessionHas('success');
        $this->assertSame('PartyRent PH', setting('platformName'));

        // suspend / reactivate, but never yourself
        $this->post("/superadmin/users/{$nina->id}/status", ['status' => 'suspended'])->assertSessionHas('success');
        $this->assertSame('suspended', $nina->fresh()->status);
        $me = User::where('email', 'super@partyrent.ph')->first();
        $this->post("/superadmin/users/{$me->id}/status", ['status' => 'suspended'])->assertSessionHas('error');
    }

    public function test_login_and_suspended_accounts(): void
    {
        $this->post('/login', ['email' => 'maria@demo.ph', 'password' => 'demo123'])->assertRedirect();
        $this->assertAuthenticated();
        auth()->logout();
        $this->post('/login', ['email' => 'miguel@demo.ph', 'password' => 'demo123']);
        $this->assertGuest();
        $this->post('/login', ['email' => 'maria@demo.ph', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
}
