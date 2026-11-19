<?php

namespace Tests\Feature;

use App\Models\RentalRequest;
use App\Models\ShopStaff;
use App\Models\User;
use App\Support\CompanyModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Company role modules (docs/ROLE_MODULES_PLAN.md): roles live inside the company and each role works in its
 * own module. Demo team of Elegant Occasion: Liza (owner), Grace (Cashier), Rico (Inventory Keeper + Field Crew),
 * Joy (Field Crew), Leah (Front Desk).
 */
class CompanyModulesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function inBusinessMode(string $email): User
    {
        $u = $this->user($email);
        $this->actingAs($u)->withSession(['mode' => 'supplier']);

        return $u;
    }

    private function shopRequest(): RentalRequest
    {
        return RentalRequest::where('supplier_id', $this->user('liza@demo.ph')->supplier->id)->firstOrFail();
    }

    public function test_system_roles_stay_platform_roles_and_company_roles_stay_in_the_company(): void
    {
        $joy = $this->user('staff@elegantoccasion.ph');
        $this->assertSame(['customer'], $joy->roles, 'no system role for company work');
        $this->assertSame(['crew'], $joy->orgRoles());
        $this->assertSame(['inventory', 'crew'], $this->user('inventory@elegantoccasion.ph')->orgRoles());
        $this->assertSame(['org_admin'], $this->user('liza@demo.ph')->orgRoles());
        $this->assertSame([], $this->user('maria@demo.ph')->orgRoles());
    }

    public function test_owner_opens_every_module_and_switches_between_them(): void
    {
        $liza = $this->inBusinessMode('liza@demo.ph');
        $this->assertSame(array_keys(CompanyModules::SHOP), $liza->modules());
        $this->get('/supplier')->assertOk()->assertSee('Your modules')->assertSee('Front Desk');

        $this->post('/module', ['module' => 'desk'])->assertRedirect(route('supplier.desk'));
        $this->get('/supplier/desk')->assertOk()->assertSee('Requests to answer');
        $this->post('/module', ['module' => 'inventory'])->assertRedirect(route('supplier.stock'));
        $this->get('/supplier/stock')->assertOk()->assertSee('Prepare for the next 3 days');
        $this->post('/module', ['module' => 'crew'])->assertRedirect(route('supplier.crew'));
        $this->get('/supplier/crew')->assertOk()->assertSee('My Jobs');

        // the owner can also sit at the till: the shop's cashier module, this shop only
        $this->post('/module', ['module' => 'cashier'])->assertRedirect(route('cashier.dashboard'));
        $this->get('/cashier')->assertOk();
        $this->assertTrue($liza->fresh()->isShopCashier());
        // a page of another module brings the owner back there
        $this->get('/supplier/products')->assertOk();
        $this->assertSame('manage', session('module'));
    }

    public function test_front_desk_works_only_in_its_module(): void
    {
        $leah = $this->inBusinessMode('desk@elegantoccasion.ph');
        $this->assertSame(['desk'], $leah->modules());
        $this->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('supplier.desk'));
        $this->get('/supplier')->assertRedirect(route('supplier.desk'));
        $this->get('/supplier/desk')->assertOk()->assertSee('Front Desk')->assertDontSee('Your modules');
        $this->get('/supplier/requests')->assertOk();
        $this->get(route('supplier.requests.show', $this->shopRequest()))->assertOk();
        $this->get('/supplier/shortfalls')->assertOk();
        // not their job: catalog, money, team, field steps
        foreach (['/supplier/products', '/supplier/payments', '/supplier/team', '/supplier/crew', '/supplier/stock'] as $url) {
            $this->get($url)->assertRedirect(route('supplier.desk'));
        }
        $this->get('/cashier')->assertRedirect(route('supplier.desk'));
        $this->post('/module', ['module' => 'manage'])->assertSessionHas('error');
    }

    public function test_field_crew_cannot_answer_requests(): void
    {
        $this->inBusinessMode('staff@elegantoccasion.ph');
        $this->get('/supplier')->assertRedirect(route('supplier.crew'));
        $this->get('/supplier/crew')->assertOk()->assertSee('My Jobs');
        $r = $this->shopRequest();
        $this->get(route('supplier.requests.show', $r))->assertOk();
        $this->post(route('supplier.requests.accept', $r))->assertRedirect(route('supplier.crew'))->assertSessionHas('error');
        $this->get('/supplier/desk')->assertRedirect(route('supplier.crew'));
    }

    public function test_member_with_two_roles_switches_modules(): void
    {
        $rico = $this->inBusinessMode('inventory@elegantoccasion.ph');
        $this->assertSame(['inventory', 'crew'], $rico->modules());
        $this->get('/supplier')->assertRedirect(route('supplier.stock'));
        $this->get('/supplier/stock')->assertOk()->assertSee('Your modules')->assertSee('Field Crew');
        $this->post('/module', ['module' => 'crew'])->assertRedirect(route('supplier.crew'));
        $this->assertSame('crew', $rico->fresh()->currentModule());
        // opening a page of the other module follows it
        $this->get('/supplier/inventory')->assertOk();
        $this->assertSame('inventory', session('module'));
        $this->post('/module', ['module' => 'cashier'])->assertSessionHas('error');
    }

    public function test_cashier_module_unchanged_for_a_cashier(): void
    {
        $this->inBusinessMode('cashier@elegantoccasion.ph');
        $this->get('/supplier')->assertRedirect(route('cashier.dashboard'));
        $this->get('/cashier')->assertOk()->assertDontSee('Your modules');
        $this->get('/supplier/requests')->assertRedirect(route('cashier.dashboard'));
    }

    public function test_owner_adds_and_removes_permissions_for_one_member(): void
    {
        $joy = $this->user('staff@elegantoccasion.ph');
        $this->inBusinessMode('liza@demo.ph');
        $this->put("/supplier/team/{$joy->id}", ['perms_set' => 1, 'perms' => ['orders.view', 'fulfillment.update', 'payments.view', 'team.manage']])->assertSessionHas('success');
        $m = ShopStaff::where('user_id', $joy->id)->first();
        $this->assertSame(['payments.view'], $m->perms_add, 'team.manage stays with the owner');
        $this->assertSame(['returns.inspect'], $m->perms_remove);
        $this->get('/supplier/team')->assertSee('Custom permissions');

        $this->inBusinessMode('staff@elegantoccasion.ph');
        $this->get('/supplier/payments')->assertOk();
        $this->get('/supplier/crew')->assertOk()->assertSee('Also allowed');
        $this->get('/supplier/damages')->assertRedirect(route('supplier.crew'));
        $this->get('/supplier/team')->assertRedirect(route('supplier.crew'));

        // changing roles keeps the tweaks that still differ from the new defaults
        $this->inBusinessMode('liza@demo.ph');
        $this->put("/supplier/team/{$joy->id}", ['roles' => ['crew', 'cashier']])->assertSessionHas('success');
        $m->refresh();
        $this->assertSame(['crew', 'cashier'], $m->roleList());
        $this->assertNull($m->perms_add, 'cashier already includes payments.view');
        $this->assertSame(['returns.inspect'], $m->perms_remove);
    }

    public function test_invite_with_several_roles(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->inBusinessMode('liza@demo.ph');
        $this->post('/supplier/team', ['email' => 'maria@demo.ph', 'roles' => ['cashier', 'desk']])->assertSessionHas('success');
        $inv = \App\Models\StaffInvite::firstOrFail();
        $this->assertSame(['cashier', 'desk'], $inv->roleList());
        $this->post('/supplier/team', ['email' => 'jose@demo.ph', 'roles' => ['org_admin']])->assertSessionHas('error');
        $this->post('/supplier/team', ['email' => 'jose@demo.ph', 'roles' => []])->assertSessionHas('error');

        $maria = $this->user('maria@demo.ph');
        $res = \App\Domain\ShopStaffs::accept($inv, $maria);
        $this->assertTrue($res['ok']);
        $this->assertSame(['cashier', 'desk'], $maria->fresh()->modules());
    }
}
