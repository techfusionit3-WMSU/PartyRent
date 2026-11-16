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

    public function test_employee_rules(): void
    {
        $this->actingAs($this->owner())->withSession(['mode' => 'supplier']);
        $this->post('/supplier/team', ['name' => 'X Y', 'email' => 'x@demo.ph', 'role' => 'org_admin', 'password' => 'temp1234A'])->assertSessionHas('error');
        $this->post('/supplier/team', ['name' => 'X Y', 'email' => 'maria@demo.ph', 'role' => 'staff', 'password' => 'temp1234A'])->assertSessionHas('error', 'That email already has a PartyRent account.');
        $this->post('/supplier/team', ['name' => 'X Y', 'email' => 'x@demo.ph', 'role' => 'staff', 'password' => 'short'])->assertSessionHas('error');
    }

    public function test_inventory_staff_permissions(): void
    {
        $u = $this->add('inventory', 'stock@demo.ph');
        $this->assertSame(['employee'], $u->roles);
        $this->assertSame('supplier', $u->defaultMode());
        $this->actingAs($u);
        $this->get('/supplier/products')->assertOk();
        $this->get('/supplier/inventory')->assertOk();
        $this->get('/supplier/payments')->assertRedirect('/supplier');
        $this->get('/supplier/team')->assertRedirect('/supplier');
        $this->get('/supplier')->assertOk()->assertSee('Inventory Staff')->assertDontSee('Switch to Customer Mode');
    }

    public function test_cashier_works_in_cashier_mode_for_their_shop(): void
    {
        $u = $this->add('cashier', 'till@demo.ph');
        $this->assertSame(['cashier'], $u->roles);
        $this->assertSame('cashier', $u->defaultMode());
        $this->assertTrue($u->orgCan('payments.verify'));
        $this->assertFalse($u->orgCan('products.manage'));
        $this->actingAs($u)->get('/cashier')->assertOk();
    }

    public function test_change_role_reset_password_and_deactivate(): void
    {
        $u = $this->add('staff', 'crew@demo.ph');
        $this->actingAs($this->owner())->withSession(['mode' => 'supplier']);
        $this->put("/supplier/team/{$u->id}", ['role' => 'cashier'])->assertSessionHas('success');
        $this->assertSame('cashier', ShopStaff::where('user_id', $u->id)->value('role'));
        $this->assertSame(['cashier'], $u->fresh()->roles);
        $this->put("/supplier/team/{$u->id}", ['status' => 'deactivated'])->assertSessionHas('success');
        $this->assertSame('deactivated', $u->fresh()->status);
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
