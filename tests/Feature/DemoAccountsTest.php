<?php

namespace Tests\Feature;

use App\Models\EventBooking;
use App\Models\ShopStaff;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Make sure demo accounts work": every account on the login page exists and signs in with demo123. */
class DemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    /** The emails the login page offers as demo buttons. */
    private function loginPageEmails(): array
    {
        preg_match_all("/'([a-z0-9.]+@[a-z0-9.]+)'\]/", file_get_contents(resource_path('views/auth/login.blade.php')), $m);

        return array_values(array_unique($m[1]));
    }

    public function test_every_demo_account_on_the_login_page_signs_in(): void
    {
        $emails = $this->loginPageEmails();
        $this->assertGreaterThanOrEqual(20, count($emails));
        $this->get('/login')->assertOk()->assertSee('Try a demo account');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // 22 sign-ins in a row

        foreach ($emails as $email) {
            $u = User::where('email', $email)->first();
            $this->assertNotNull($u, $email.' is on the login page but not seeded');
            $res = $this->post('/login', ['email' => $email, 'password' => 'demo123']);
            if ($u->status === 'suspended') { // Miguel shows how a suspended account is refused
                $this->assertGuest();
                continue;
            }
            $res->assertRedirect();
            $this->assertAuthenticatedAs($u);
            $this->followingRedirects()->get($res->headers->get('Location'))->assertOk();
            $this->post('/logout');
            $this->flushSession();
        }
    }

    public function test_missing_demo_accounts_are_added_without_erasing_anything(): void
    {
        // an older database: no Front Desk Leah, no PartyRent cashier, no event company
        $gf = Supplier::where('name', 'Grand Fiesta Events Co.')->firstOrFail();
        $team = ShopStaff::where('supplier_id', $gf->id)->pluck('user_id')->push($gf->owner_id);
        EventBooking::where('supplier_id', $gf->id)->get()->each->delete();
        \App\Models\EventTeam::where('supplier_id', $gf->id)->delete();
        \App\Models\SupplierEventType::where('supplier_id', $gf->id)->delete();
        \App\Models\EventPackage::where('supplier_id', $gf->id)->delete();
        ShopStaff::where('supplier_id', $gf->id)->delete();
        $gf->delete();
        User::whereIn('id', $team)->delete();
        $leah = User::where('email', 'desk@elegantoccasion.ph')->firstOrFail();
        ShopStaff::where('user_id', $leah->id)->delete();
        $leah->delete();
        User::where('email', 'cashier@partyrent.ph')->delete();
        $users = User::count();

        $this->artisan('partyrent:demo-accounts')->expectsOutputToContain('Added 6 demo account(s)')->assertOk();
        $this->assertSame($users + 6, User::count());
        foreach ($this->loginPageEmails() as $email) {
            $this->assertTrue(User::where('email', $email)->exists(), $email);
        }
        $this->assertSame(['desk'], ShopStaff::where('user_id', User::where('email', 'desk@elegantoccasion.ph')->value('id'))->value('roles'));
        $this->assertSame(3, Supplier::where('name', 'Grand Fiesta Events Co.')->firstOrFail()->eventBookings()->count());

        // running it again adds nothing
        $this->artisan('partyrent:demo-accounts')->expectsOutputToContain('already there')->assertOk();
        $this->assertSame($users + 6, User::count());
    }
}
