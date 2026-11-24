<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\RegistrationCode;
use App\Support\AccountRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** Registration: legal name in parts, birthday (18+), gender, stronger passwords; profile, password change and reset. */
class AccountRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function form(array $x = []): array
    {
        return array_merge([
            'first_name' => 'Nina', 'middle_name' => '', 'last_name' => 'Reyes', 'birthday' => today()->subYears(30)->format('Y-m-d'), 'gender' => 'female',
            'email' => 'nina@gmail.com', 'phone' => '0917 555 0101', 'addr' => ['regionCode' => '070000000', 'provinceCode' => '072200000', 'cityCode' => '072217000'],
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'terms' => '1',
        ], $x);
    }

    /** Sign up and enter the emailed code; returns the new account. */
    private function signUp(array $x = []): User
    {
        Notification::fake();
        $this->post('/register', $this->form($x))->assertSessionHasNoErrors()->assertRedirect(route('register.verify'));
        $code = null;
        Notification::assertSentOnDemand(RegistrationCode::class, function ($n) use (&$code) { $code = $n->code; return true; });
        $this->post('/register/verify', ['code' => $code])->assertRedirect(route('home'));

        return User::where('email', $x['email'] ?? 'nina@gmail.com')->firstOrFail();
    }

    public function test_the_form_shows_the_new_fields_and_password_rules(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('name="first_name"', false)->assertSee('name="middle_name"', false)->assertSee('name="last_name"', false)
            ->assertSee('name="birthday"', false)->assertSee('type="date"', false)->assertSee('name="gender"', false)->assertSee('Prefer not to say')
            ->assertSee('At least 8 characters')->assertSee('One uppercase letter (A–Z)')->assertSee('One lowercase letter (a–z)')
            ->assertSee('max="'.today()->subYears(18)->format('Y-m-d').'"', false)->assertDontSee('name="name"', false);
    }

    public function test_names(): void
    {
        // first + last, no middle name
        $u = $this->signUp();
        $this->assertSame(['Nina', null, 'Reyes', 'Nina Reyes'], [$u->first_name, $u->middle_name, $u->last_name, $u->name]);
        $this->assertSame('female', $u->gender);
        $this->assertSame(today()->subYears(30)->format('Y-m-d'), $u->birthday->format('Y-m-d'));
        $this->assertSame(30, $u->age());
        // with a middle name: the full name everywhere includes it
        $this->post('/logout');
        $v = $this->signUp(['email' => 'ana.m@gmail.com', 'first_name' => 'Ana Marie', 'middle_name' => 'Lopez', 'last_name' => 'dela Cruz']);
        $this->assertSame(['Ana Marie', 'Lopez', 'dela Cruz', 'Ana Marie Lopez dela Cruz'], [$v->first_name, $v->middle_name, $v->last_name, $v->name]);
        $this->assertSame('Ana', $v->firstName());
        $this->get(route('profile'))->assertOk()->assertSee('Ana Marie')->assertSee('value="Lopez"', false);

        // missing first or last name / not a name
        $this->post('/logout');
        $this->post('/register', $this->form(['email' => 'x1@gmail.com', 'first_name' => '']))->assertSessionHasErrors(['first_name' => 'Enter your first name.']);
        $this->post('/register', $this->form(['email' => 'x2@gmail.com', 'last_name' => ' ']))->assertSessionHasErrors(['last_name' => 'Enter your last name.']);
        $this->post('/register', $this->form(['email' => 'x3@gmail.com', 'first_name' => 'N1na']))->assertSessionHasErrors('first_name');
        $this->assertSame(0, PendingRegistration::whereIn('email', ['x1@gmail.com', 'x2@gmail.com', 'x3@gmail.com'])->count());
    }

    public function test_age_is_worked_out_from_the_birthday(): void
    {
        // exactly 18 today: allowed; one day short of 18: refused (backend, not just the date picker)
        $this->signUp(['email' => 'eighteen@gmail.com', 'birthday' => today()->subYears(18)->format('Y-m-d')]);
        $this->post('/logout');
        $this->post('/register', $this->form(['email' => 'young@gmail.com', 'birthday' => today()->subYears(18)->addDay()->format('Y-m-d')]))
            ->assertSessionHasErrors(['birthday' => 'You must be at least 18 years old to have a PartyRent account (you are 17).']);
        $this->post('/register', $this->form(['email' => 'young@gmail.com', 'birthday' => today()->subYears(12)->format('Y-m-d')]))->assertSessionHasErrors('birthday');
        // in the future / not a date / missing
        $this->post('/register', $this->form(['email' => 'f@gmail.com', 'birthday' => today()->addDay()->format('Y-m-d')]))->assertSessionHasErrors(['birthday' => 'Your birthday cannot be in the future.']);
        $this->post('/register', $this->form(['email' => 'f@gmail.com', 'birthday' => '2001-02-30']))->assertSessionHasErrors(['birthday' => 'Enter a valid birthday.']);
        $this->post('/register', $this->form(['email' => 'f@gmail.com', 'birthday' => 'yesterday']))->assertSessionHasErrors('birthday');
        $this->post('/register', $this->form(['email' => 'f@gmail.com', 'birthday' => '']))->assertSessionHasErrors(['birthday' => 'Enter your birthday.']);
        // an age field sent by hand is ignored: only the birthday counts
        $this->post('/register', $this->form(['email' => 'f@gmail.com', 'age' => 30, 'birthday' => today()->subYears(10)->format('Y-m-d')]))->assertSessionHasErrors('birthday');

        // the age counts whether this year's birthday has come
        $on = Carbon::parse('2026-06-15');
        $this->assertSame(25, AccountRules::age('2001-06-15', $on));  // birthday today
        $this->assertSame(24, AccountRules::age('2001-06-16', $on));  // tomorrow
        $this->assertSame(25, AccountRules::age('2001-01-01', $on));  // already this year
        $this->assertSame(17, AccountRules::age('2008-02-29', Carbon::parse('2026-02-28'))); // leap day: counts from Mar 1
        $this->assertSame(18, AccountRules::age('2008-02-29', Carbon::parse('2026-03-01')));
    }

    public function test_gender(): void
    {
        $this->post('/register', $this->form(['gender' => '']))->assertSessionHasErrors(['gender' => 'Choose your gender (or "Prefer not to say").']);
        $this->post('/register', $this->form(['gender' => 'robot']))->assertSessionHasErrors(['gender' => 'Choose one of the gender options.']);
        $u = $this->signUp(['gender' => 'prefer_not']);
        $this->assertSame(['prefer_not', 'Prefer not to say'], [$u->gender, $u->genderLabel()]);
    }

    public function test_passwords(): void
    {
        $try = fn ($pw, $confirm = null) => $this->post('/register', $this->form(['password' => $pw, 'password_confirmation' => $confirm ?? $pw]));
        $try('Sh0rt')->assertSessionHasErrors(['password' => 'Your password needs at least 8 characters.']);
        $try('alllowercase1')->assertSessionHasErrors(['password' => 'Your password needs at least one uppercase letter (A–Z) and one lowercase letter (a–z).']);
        $try('ALLUPPERCASE1')->assertSessionHasErrors('password');
        $try('Secret123', 'Secret124')->assertSessionHasErrors(['password' => 'The two passwords do not match.']);
        $u = $this->signUp(['password' => 'GoodPassword', 'password_confirmation' => 'GoodPassword']);
        $this->assertNotSame('GoodPassword', $u->getRawOriginal('password'));      // hashed, never plain text
        $this->assertTrue(Hash::check('GoodPassword', $u->password));
    }

    public function test_existing_accounts_profile_password_change_and_reset(): void
    {
        // existing demo accounts keep their data (the name split into parts) and still log in with their old password
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->assertSame(['Maria', 'Santos', 'Maria Santos'], [$maria->first_name, $maria->last_name, $maria->name]);
        $this->post('/login', ['email' => 'maria@demo.ph', 'password' => 'demo123'])->assertRedirect();
        $this->assertAuthenticatedAs($maria);

        // profile: name parts, birthday, gender (18+ checked here too)
        $base = ['first_name' => 'Maria', 'middle_name' => 'Cruz', 'last_name' => 'Santos', 'birthday' => '1994-03-12', 'gender' => 'female', 'email' => $maria->email, 'phone' => $maria->phone, 'addr' => $maria->address_parts];
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->put(route('account.update'), $base)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('Maria Cruz Santos', $maria->fresh()->name);
        $this->put(route('account.update'), array_merge($base, ['birthday' => today()->subYears(17)->format('Y-m-d')]))->assertSessionHasErrors('birthday');
        $this->put(route('account.update'), array_merge($base, ['last_name' => '']))->assertSessionHasErrors('last_name');
        $this->assertSame('Maria Cruz Santos', $maria->fresh()->name);

        // password change: same rules
        $this->put(route('account.password'), ['cur' => 'demo123', 'np' => 'weakpass1', 'np_confirmation' => 'weakpass1'])->assertSessionHasErrors('np');
        $this->put(route('account.password'), ['cur' => 'demo123', 'np' => 'Stronger1', 'np_confirmation' => 'Stronger2'])->assertSessionHasErrors(['np' => 'The two passwords do not match.']);
        $this->put(route('account.password'), ['cur' => 'demo123', 'np' => 'Stronger1', 'np_confirmation' => 'Stronger1'])->assertSessionHas('success');
        $this->assertTrue(Hash::check('Stronger1', $maria->fresh()->password));

        // password reset: same rules
        $this->post('/logout');
        $token = Password::createToken($maria->fresh());
        $this->post(route('password.update'), ['token' => $token, 'email' => $maria->email, 'password' => 'nouppercase1', 'password_confirmation' => 'nouppercase1'])->assertSessionHasErrors('password');
        $this->post(route('password.update'), ['token' => $token, 'email' => $maria->email, 'password' => 'ResetPass1', 'password_confirmation' => 'ResetPass1'])->assertRedirect(route('login'));
        $this->post('/login', ['email' => 'maria@demo.ph', 'password' => 'ResetPass1'])->assertRedirect();
        $this->assertAuthenticatedAs($maria);

        // PartyRent staff may leave birthday and gender empty on their profile
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->put(route('account.update'), ['first_name' => 'Mark', 'last_name' => 'Reyes', 'email' => $admin->email, 'phone' => $admin->phone, 'address' => $admin->address])
            ->assertSessionHasNoErrors();
        // admins see age and gender in the user list
        $this->get(route('admin.users', ['q' => 'maria']))->assertOk()->assertSee('Age '.AccountRules::age('1994-03-12'))->assertSee('Female');
    }
}
