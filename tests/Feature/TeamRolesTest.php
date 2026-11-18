<?php

namespace Tests\Feature;

use App\Models\ShopStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Team & Roles: organization roles and permissions (port of Auth.ORG_PERMS / Services.addEmployee). */
class TeamRolesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function owner(): User
    {
        return User::where('email', 'juan@demo.ph')->firstOrFail();
    }

    private function add(string $role, string $email): User
    {
        $this->actingAs($this->owner())->withSession(['mode' => 'supplier'])
            ->post('/supplier/team', ['name' => 'New Person', 'email' => $email, 'phone' => '0917 000 0000', 'role' => $role, 'password' => 'temp1234A'])
            ->assertSessionHas('success');

        return User::where('email', $email)->firstOrFail();
    }

    public function test_owner_is_org_admin_and_sees_team_page(): void
    {
        $this->assertSame('org_admin', $this->owner()->orgRole());
        $this->actingAs($this->owner())->withSession(['mode' => 'supplier'])->get('/supplier/team')
            ->assertOk()->assertSee('Team & Roles')->assertSee('What each role can do')->assertSee('Account owner');
    }

    /** Customer ⇄ Supplier mode (reference Auth.setMode / guard): menu shows the shop name, pages follow the mode. */
    public function test_mode_switch_both_ways(): void
    {
        $juan = $this->owner();
        $this->actingAs($juan)->withSession(['mode' => 'customer'])->get('/')->assertOk()->assertSee($juan->supplier->name);
        $this->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('supplier.dashboard'));
        $this->assertSame('supplier', session('mode'));
        $this->get(route('supplier.dashboard'))->assertOk()->assertSee('Switch to Customer Mode');
        $this->post('/mode', ['mode' => 'customer'])->assertRedirect(route('home'));
        $this->assertSame('customer', session('mode'));
        // opening a supplier page switches back automatically
        $this->get('/supplier/products')->assertOk();
        $this->assertSame('supplier', session('mode'));

        // a customer without an approved shop cannot switch
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->post('/mode', ['mode' => 'supplier'])->assertSessionHas('error');
        $this->assertSame('customer', session('mode'));
        $this->get(route('supplier.dashboard'))->assertRedirect(route('start-selling'));
    }

    public function test_employee_rules(): void
    {
        $this->actingAs($this->owner())->withSession(['mode' => 'supplier']);
        $this->post('/supplier/team', ['name' => 'X Y', 'email' => 'x@demo.ph', 'role' => 'org_admin', 'password' => 'temp1234A'])->assertSessionHas('error');
        // another shop's owner, platform staff and a new email without a proper password cannot be added
        $this->post('/supplier/team', ['email' => 'ana@demo.ph', 'role' => 'staff'])->assertSessionHas('error');
        $this->post('/supplier/team', ['email' => 'admin@partyrent.ph', 'role' => 'cashier'])->assertSessionHas('error');
        $this->post('/supplier/team', ['name' => 'X Y', 'email' => 'x@demo.ph', 'role' => 'staff', 'password' => 'short'])->assertSessionHas('error');
        $this->post('/supplier/team', ['email' => 'nobody@demo.ph', 'role' => 'staff'])->assertSessionHas('error');
        // someone already working for another shop
        $this->post('/supplier/team', ['email' => 'cashier@elegantoccasion.ph', 'role' => 'cashier'])->assertSessionHas('error');
    }

    /** An existing customer account joins the team as it is: same login, Business Mode for the shop. */
    public function test_existing_customer_account_joins_the_team(): void
    {
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $hash = $maria->password;
        $this->actingAs($this->owner())->withSession(['mode' => 'supplier'])
            ->post('/supplier/team', ['email' => 'MARIA@demo.ph', 'role' => 'cashier'])->assertSessionHas('success');
        $maria->refresh();
        $this->assertSame(['customer'], $maria->roles);
        $this->assertSame($hash, $maria->password);
        $this->assertSame($this->owner()->supplier->id, $maria->shop()->id);
        $this->assertTrue($maria->isShopCashier());
        $this->assertSame(1, \App\Models\AppNotification::where('user_id', $maria->id)->where('title', 'like', 'You joined%')->count());
        // her own rentals stay hers in Customer Mode
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->get('/rentals')->assertOk();
    }

    public function test_inventory_staff_permissions(): void
    {
        $u = $this->add('inventory', 'stock@demo.ph');
        $this->assertSame(['customer'], $u->roles);
        $this->assertSame('customer', $u->defaultMode());
        $this->actingAs($u)->withSession(['mode' => 'customer']);
        $this->get('/')->assertOk()->assertSee('Switch to Business Mode');
        $this->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('supplier.dashboard'));
        $this->get('/supplier/products')->assertOk();
        $this->get('/supplier/inventory')->assertOk();
        $this->get('/supplier/payments')->assertRedirect('/supplier');
        $this->get('/supplier/team')->assertRedirect('/supplier');
        $this->get('/supplier')->assertOk()->assertSee('Inventory Staff')->assertSee('Switch to Customer Mode');
    }

    public function test_cashier_logs_in_as_customer_and_switches_to_business_mode(): void
    {
        $u = $this->add('cashier', 'till@demo.ph');
        $this->assertSame(['customer'], $u->roles);
        $this->assertSame('customer', $u->defaultMode());
        $this->assertTrue($u->orgCan('payments.verify'));
        $this->assertFalse($u->orgCan('products.manage'));
        $this->assertTrue($u->hasPerm('reports.view'));

        $this->post('/logout');
        $this->post('/login', ['email' => 'till@demo.ph', 'password' => 'temp1234A'])->assertRedirect(route('home'));
        $this->assertSame('customer', session('mode'));
        $this->get('/')->assertOk()->assertSee('Switch to Business Mode');
        $this->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('cashier.dashboard'));
        $this->assertSame('cashier', $u->fresh()->activeRole(session('mode')));
        $this->get('/cashier')->assertOk()->assertSee('Switch to Customer Mode');
        // a cashier's Business Mode is the cashier workspace, not the supplier one
        $this->get('/supplier/products')->assertRedirect(route('cashier.dashboard'));
        $this->post('/mode', ['mode' => 'customer'])->assertRedirect(route('home'));
        $this->get('/rentals')->assertOk();
    }

    public function test_change_role_and_deactivate(): void
    {
        $u = $this->add('staff', 'crew@demo.ph');
        $this->actingAs($this->owner())->withSession(['mode' => 'supplier']);
        $this->put("/supplier/team/{$u->id}", ['role' => 'cashier'])->assertSessionHas('success');
        $this->assertSame('cashier', ShopStaff::where('user_id', $u->id)->value('role'));
        $this->assertSame(['customer'], $u->fresh()->roles);
        $this->put("/supplier/team/{$u->id}", ['status' => 'deactivated'])->assertSessionHas('success');
        // they lose the shop, not their own account
        $this->assertSame('deactivated', ShopStaff::where('user_id', $u->id)->value('status'));
        $this->assertSame('active', $u->fresh()->status);
        $this->assertNull($u->fresh()->shop());
        $this->actingAs($u->fresh())->withSession(['mode' => 'customer'])->post('/mode', ['mode' => 'supplier'])->assertSessionHas('error');
        $this->get('/cashier')->assertRedirect();
        // reactivate
        $this->actingAs($this->owner())->withSession(['mode' => 'supplier'])->put("/supplier/team/{$u->id}", ['status' => 'active'])->assertSessionHas('success');
        $this->assertNotNull($u->fresh()->shop());
        // the owner cannot be managed, nor can another shop's people
        $this->put('/supplier/team/'.$this->owner()->id, ['role' => 'staff'])->assertSessionHas('error');
        $ana = User::where('email', 'ana@demo.ph')->first();
        $this->put("/supplier/team/{$ana->id}", ['role' => 'staff'])->assertSessionHas('error');
    }

    public function test_route_permission_map(): void
    {
        $this->assertSame('orders.manage', ShopStaff::permFor('supplier.requests.accept'));
        $this->assertSame('orders.view', ShopStaff::permFor('supplier.requests.show'));
        $this->assertSame('payments.view', ShopStaff::permFor('supplier.payments'));
        $this->assertNull(ShopStaff::permFor('supplier.dashboard'));
        $this->assertTrue(ShopStaff::allows('staff', 'supplier.requests.advance'));
        $this->assertFalse(ShopStaff::allows('staff', 'supplier.requests.accept'));
    }
}
