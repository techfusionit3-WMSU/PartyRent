<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\RegistrationCode;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Sign-up creates the account only after the emailed code is confirmed; changing email later needs re-verifying. */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function register(string $email = 'new.user@gmail.com')
    {
        return $this->post('/register', [
            'name' => 'Nina Reyes', 'email' => $email, 'phone' => '0917 555 0101',
            'addr' => ['regionCode' => '070000000', 'provinceCode' => '072200000', 'cityCode' => '072217000'],
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => '1',
        ]);
    }

    /** The code that was emailed in the most recent RegistrationCode notification. */
    private function sentCode(): string
    {
        $code = null;
        Notification::assertSentOnDemand(RegistrationCode::class, function (RegistrationCode $n) use (&$code) { $code = $n->code; return true; });

        return $code;
    }

    public function test_no_account_exists_until_the_emailed_code_is_entered(): void
    {
        Notification::fake();
        $this->register()->assertRedirect(route('register.verify'));
        $this->assertDatabaseMissing('users', ['email' => 'new.user@gmail.com']);
        $this->assertGuest();
        Notification::assertSentOnDemand(RegistrationCode::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'new.user@gmail.com');
        $code = $this->sentCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringNotContainsString($code, (string) PendingRegistration::first()->getRawOriginal('code_hash'));

        $this->get('/register/verify')->assertOk()->assertSee('new.user@gmail.com');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post('/register/verify', ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('users', ['email' => 'new.user@gmail.com']);

        $this->post('/register/verify', ['code' => $code])->assertRedirect(route('profile', ['welcome' => 1]));
        $u = User::where('email', 'new.user@gmail.com')->firstOrFail();
        $this->assertTrue($u->hasVerifiedEmail());
        $this->assertAuthenticatedAs($u);
        $this->assertSame(0, PendingRegistration::count());
        $this->get('/cart')->assertOk();

        // the password typed at sign-up works
        $this->post('/logout');
        $this->post('/login', ['email' => 'new.user@gmail.com', 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticatedAs($u);
    }

    public function test_wrong_codes_lock_out_and_resend_issues_a_new_code(): void
    {
        Notification::fake();
        $this->register();
        $first = $this->sentCode();
        $wrong = $first === '000000' ? '111111' : '000000';
        for ($i = 0; $i < PendingRegistration::MAX_ATTEMPTS; $i++) {
            $this->post('/register/verify', ['code' => $wrong]);
        }
        $this->post('/register/verify', ['code' => $first])->assertSessionHasErrors('code'); // locked even with the right code
        $this->assertDatabaseMissing('users', ['email' => 'new.user@gmail.com']);

        $this->post('/register/resend')->assertSessionHas('error'); // too soon
        $this->travel(61)->seconds();
        $this->post('/register/resend')->assertSessionHas('success');
        Notification::assertSentOnDemandTimes(RegistrationCode::class, 2);
        $this->post('/register/verify', ['code' => $this->latestCode()])->assertRedirect(route('profile', ['welcome' => 1]));
        $this->assertDatabaseHas('users', ['email' => 'new.user@gmail.com']);
    }

    public function test_expired_codes_and_taken_emails_are_refused(): void
    {
        Notification::fake();
        $this->register('maria@demo.ph')->assertSessionHasErrors('email');
        Notification::assertNothingSent();

        $this->register();
        $code = $this->sentCode();
        $this->travel(PendingRegistration::TTL_MINUTES + 1)->minutes();
        $this->post('/register/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('users', ['email' => 'new.user@gmail.com']);
    }

    private function latestCode(): string
    {
        $codes = [];
        Notification::assertSentOnDemand(RegistrationCode::class, function (RegistrationCode $n) use (&$codes) { $codes[] = $n->code; return true; });

        return end($codes);
    }

    public function test_changing_email_requires_verifying_again(): void
    {
        Notification::fake();
        $maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->assertTrue($maria->hasVerifiedEmail());
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->put('/account', [
            'name' => $maria->name, 'email' => 'maria.new@gmail.com', 'phone' => $maria->phone,
            'addr' => ['regionCode' => '110000000', 'provinceCode' => '112400000', 'cityCode' => '112402000', 'barangay' => 'Bajada', 'street' => '1 Test St.'],
        ])->assertRedirect(route('verification.notice'));
        $this->assertFalse($maria->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($maria->fresh(), VerifyEmail::class);
    }
}
