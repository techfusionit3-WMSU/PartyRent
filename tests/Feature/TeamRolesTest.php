<?php

namespace Tests\Feature;

use App\Models\ShopStaff;
use App\Models\StaffInvite;
use App\Models\User;
use App\Notifications\StaffInviteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    /** Owner (default Juan) invites $email; returns the emailed [path, code] (code null for an existing account). */
    private function invite(string $role, string $email, ?User $owner = null): array
    {
        Notification::fake();
        $this->actingAs($owner ?? $this->owner())->withSession(['mode' => 'supplier'])
            ->post('/supplier/team', ['email' => $email, 'role' => $role])->assertSessionHas('success');
        $mail = null;
        Notification::assertSentOnDemand(StaffInviteMail::class, function ($n, $ch, $notifiable) use ($email, &$mail) {
            $mail = $n;

            return $notifiable->routes['mail'] === strtolower($email);
        });

        return [parse_url($mail->url, PHP_URL_PATH), $mail->code];
    }

    /** A new email: invited, then creates their own account with the emailed code and their own password. */
    private function add(string $role, string $email): User
    {
        [$path, $code] = $this->invite($role, $email);
        $this->assertNotNull($code);
        $this->assertNull(User::where('email', $email)->first(), 'no account before they confirm');
        $this->post('/logout');
        $this->get($path)->assertOk()->assertSee('Create account &amp; join', false);
        $this->post($path.'/join', ['code' => $code, 'name' => 'New Person', 'phone' => '0917 000 0000', 'password' => 'temp1234A', 'password_confirmation' => 'temp1234A'])
            ->assertSessionHas('success');
        $u = User::where('email', $email)->firstOrFail();
        $this->assertAuthenticatedAs($u);
        $this->assertTrue($u->hasVerifiedEmail());
        $this->post('/logout');

        return $u;
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
        $this->post('/supplier/team', ['email' => 'x@demo.ph', 'role' => 'org_admin'])->assertSessionHas('error');
        // another shop's owner, platform staff and someone already working for another shop cannot be invited
        $this->post('/supplier/team', ['email' => 'ana@demo.ph', 'role' => 'crew'])->assertSessionHas('error');
        $this->post('/supplier/team', ['email' => 'admin@partyrent.ph', 'role' => 'cashier'])->assertSessionHas('error');
        $this->post('/supplier/team', ['email' => 'cashier@elegantoccasion.ph', 'role' => 'cashier'])->assertSessionHas('error');
        $this->post('/supplier/team', ['email' => 'not-an-email', 'role' => 'crew'])->assertSessionHas('error');
        $this->assertSame(0, StaffInvite::count());

        // the live check in the form
        $this->postJson('/supplier/team/check', ['email' => 'cashier@elegantoccasion.ph'])->assertJson(['state' => 'blocked']);
        $this->postJson('/supplier/team/check', ['email' => 'nobody@demo.ph'])->assertJson(['state' => 'new']);
        $this->postJson('/supplier/team/check', ['email' => 'maria@demo.ph'])->assertJson(['state' => 'existing'])
            ->assertJsonMissing(['message' => 'Maria Santos'])->assertSee('M*** S.');
    }

    /** A new email gets a code; the person sets their own password. Wrong codes are limited. */
    public function test_new_email_joins_with_the_emailed_code(): void
    {
        [$path, $code] = $this->invite('crew', 'fresh@demo.ph');
        $this->get('/supplier/team')->assertSee('fresh@demo.ph')->assertSee('Invited · waiting');
        // inviting the same email twice is refused
        $this->post('/supplier/team', ['email' => 'fresh@demo.ph', 'role' => 'crew'])->assertSessionHas('error');
        $this->post('/logout');

        $wrong = $code === '000000' ? '111111' : '000000';
        $form = ['name' => 'Fresh Hire', 'phone' => '0917 000 0001', 'password' => 'mypass123', 'password_confirmation' => 'mypass123'];
        $this->post($path.'/join', ['code' => $wrong] + $form)->assertSessionHasErrors('code');
        $this->assertNull(User::where('email', 'fresh@demo.ph')->first());
        $this->post($path.'/join', ['code' => $code] + $form)->assertRedirect();
        $u = User::where('email', 'fresh@demo.ph')->firstOrFail();
        $this->assertSame($this->owner()->supplier->id, $u->shop()->id);
        $this->assertSame('supplier', session('mode'));
        $this->assertSame(0, StaffInvite::count());
        // their own password works, and the link is used up
        $this->post('/logout');
        $this->post('/login', ['email' => 'fresh@demo.ph', 'password' => 'mypass123'])->assertRedirect();
        $this->assertAuthenticatedAs($u);
        $this->get($path)->assertOk()->assertSee('Invitation not available');
    }

    public function test_owner_can_resend_and_cancel_an_invitation(): void
    {
        [$path] = $this->invite('cashier', 'later@demo.ph');
        $inv = StaffInvite::firstOrFail();
        $this->post("/supplier/team/invites/{$inv->id}/resend")->assertSessionHas('error'); // wait 60 s
        $this->travel(2)->minutes();
        $this->post("/supplier/team/invites/{$inv->id}/resend")->assertSessionHas('success');
        $this->get($path)->assertOk()->assertSee('Invitation not available'); // the old link no longer works
        $this->delete("/supplier/team/invites/{$inv->id}")->assertSessionHas('success');
        $this->assertSame(0, StaffInvite::count());
        // another shop cannot touch Juan's invitations
        $this->invite('cashier', 'again@demo.ph');
        $liza = User::where('email', 'liza@demo.ph')->firstOrFail();
        $other = StaffInvite::firstOrFail();
        $this->actingAs($liza)->withSession(['mode' => 'supplier'])->delete("/supplier/team/invites/{$other->id}")->assertSessionHas('error');
        $this->assertSame(1, StaffInvite::count());
    }

    /** Someone deactivated at one shop is free to join another; the first shop can't then reactivate them. */
    public function test_deactivated_elsewhere_can_join_another_shop(): void
    {
        $liza = User::where('email', 'liza@demo.ph')->firstOrFail();
        $grace = User::where('email', 'cashier@elegantoccasion.ph')->firstOrFail();
        $this->actingAs($liza)->withSession(['mode' => 'supplier'])->put("/supplier/team/{$grace->id}", ['status' => 'deactivated'])->assertSessionHas('success');

        [$path, $code] = $this->invite('cashier', $grace->email);
        $this->assertNull($code, 'an existing account accepts instead of typing a code');
        $this->post('/logout');
        $this->get($path)->assertRedirect(route('login'));
        $this->actingAs($grace)->withSession(['mode' => 'customer'])->get($path)->assertOk()->assertSee('Accept &amp; join', false);
        $this->post($path.'/accept')->assertRedirect();
        $grace->refresh();
        $this->assertSame($this->owner()->supplier->id, $grace->shop()->id);
        // Elegant Occasion still lists her as deactivated, and cannot reactivate her now
        $this->assertSame('deactivated', ShopStaff::where('user_id', $grace->id)->where('supplier_id', $liza->supplier->id)->value('status'));
        $this->actingAs($liza)->withSession(['mode' => 'supplier'])->put("/supplier/team/{$grace->id}", ['status' => 'active'])->assertSessionHas('error');
        $this->assertSame($this->owner()->supplier->id, $grace->fresh()->shop()->id);
    }

    public function test_invitation_for_someone_else_and_decline(): void
    {
        [$path] = $this->invite('crew', 'maria@demo.ph');
        $jose = User::where('email', 'jose@demo.ph')->firstOrFail();
        $this->actingAs($jose)->withSession(['mode' => 'customer'])->get($path)->assertOk()->assertSee('this invitation is for');
        $this->post($path.'/accept')->assertSessionHas('error');
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->post($path.'/decline')->assertSessionHas('success');
        $this->assertNull($maria->fresh()->shop());
        $this->assertSame(0, StaffInvite::count());
    }

    /** An existing customer account joins the team as it is once they accept: same login, Business Mode for the shop. */
    public function test_existing_customer_account_joins_the_team(): void
    {
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $hash = $maria->password;
        [$path] = $this->invite('cashier', 'MARIA@demo.ph');
        $this->assertNull($maria->fresh()->shop(), 'not on the team until she accepts');
        $this->assertSame(1, \App\Models\AppNotification::where('user_id', $maria->id)->where('title', 'like', 'Invitation to join%')->count());
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->post($path.'/accept')->assertRedirect(route('cashier.dashboard'));
        $maria->refresh();
        $this->assertSame(['customer'], $maria->roles);
        $this->assertSame($hash, $maria->password);
        $this->assertSame($this->owner()->supplier->id, $maria->shop()->id);
        $this->assertTrue($maria->isShopCashier());
        $this->assertSame(1, \App\Models\AppNotification::where('user_id', $this->owner()->id)->where('title', 'like', '%joined your team')->count());
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
        // their Business Mode opens the Inventory module, not the owner's dashboard
        $this->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('supplier.stock'));
        $this->get('/supplier/products')->assertOk();
        $this->get('/supplier/inventory')->assertOk();
        $this->get('/supplier/payments')->assertRedirect('/supplier/stock');
        $this->get('/supplier/team')->assertRedirect('/supplier/stock');
        $this->get('/supplier')->assertRedirect('/supplier/stock');
        $this->get('/supplier/stock')->assertOk()->assertSee('Stock Room')->assertSee('Inventory Keeper')->assertSee('Switch to Customer Mode');
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
        $u = $this->add('crew', 'crew@demo.ph');
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
        $this->put('/supplier/team/'.$this->owner()->id, ['role' => 'crew'])->assertSessionHas('error');
        $ana = User::where('email', 'ana@demo.ph')->first();
        $this->put("/supplier/team/{$ana->id}", ['role' => 'crew'])->assertSessionHas('error');
    }

    public function test_route_permission_map(): void
    {
        $this->assertSame('orders.manage', ShopStaff::permFor('supplier.requests.accept'));
        $this->assertSame('orders.view', ShopStaff::permFor('supplier.requests.show'));
        $this->assertSame('payments.view', ShopStaff::permFor('supplier.payments'));
        $this->assertNull(ShopStaff::permFor('supplier.dashboard'));
        $this->assertTrue(ShopStaff::allows('crew', 'supplier.requests.advance'));
        $this->assertFalse(ShopStaff::allows('crew', 'supplier.requests.accept'));
    }
}
