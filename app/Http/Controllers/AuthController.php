<?php

namespace App\Http\Controllers;

use App\Domain\Notifier;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\RegistrationCode;
use App\Support\AddressInput;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $cred = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt(['email' => strtolower(trim($cred['email'])), 'password' => $cred['password']], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Incorrect email or password.'])->onlyInput('email');
        }
        $u = $request->user();
        if (! $u->isActive()) {
            Auth::logout();

            return back()->with('error', 'This account is '.$u->status.'. Please contact PartyRent support.')->onlyInput('email');
        }
        $request->session()->regenerate();
        session(['mode' => $u->defaultMode()]);
        // Reference post-login prompt: customers choose rental dates or just browse (once per login).
        \App\Support\RentalPeriod::clear();
        if (! $u->isStaff()) {
            session()->flash(\App\Support\RentalPeriod::ASK, true);
        }
        $u->forceFill(['last_login_at' => now()])->save();
        if ($u->isStaff()) {
            Notifier::audit('Signed in', $u->email, $u);
        }
        $home = route($u->homeRoute($u->defaultMode()));

        // Customers may return to the page they were on; staff always land on their dashboard.
        return $u->isStaff() ? redirect($home)->with('success', 'Welcome, '.$u->firstName().'!') : redirect()->intended($home)->with('success', 'Welcome, '.$u->firstName().'!');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            ...\App\Support\AccountRules::name(), ...\App\Support\AccountRules::birthday(), ...\App\Support\AccountRules::gender(),
            'email' => 'required|email|max:180|unique:users,email',
            'phone' => ['required', 'string', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'],
            ...AddressInput::rules('addr', false), // city is enough to sign up; street/barangay can come at checkout
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'terms' => 'accepted',
        ], array_merge(AddressInput::messages(), \App\Support\AccountRules::messages(), ['phone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).', 'email.unique' => 'An account with this email already exists. Try logging in.']));

        // No account yet: hold the details until the email code is confirmed. Signing up again with the same email replaces the old attempt.
        $email = strtolower(trim($data['email']));
        $pending = PendingRegistration::firstOrNew(['email' => $email]);
        $pending->payload = array_merge([
            'name' => \App\Support\AccountRules::fullName($data['first_name'], $data['middle_name'] ?? null, $data['last_name']),
            'first_name' => trim($data['first_name']), 'middle_name' => trim((string) ($data['middle_name'] ?? '')) ?: null, 'last_name' => trim($data['last_name']),
            'birthday' => $data['birthday'], 'gender' => $data['gender'],
            'phone' => trim($data['phone']), 'password' => Hash::make($data['password']),
        ], AddressInput::attributes($data['addr']));
        $code = $pending->newCode();
        session(['pending_reg' => $pending->id]);
        $sent = self::sendCode($pending, $code);

        return redirect()->route('register.verify')->with($sent ? 'success' : 'error', $sent
            ? 'We sent a 6-digit code to '.$email.'.'
            : 'We could not send the code to '.$email.' right now. Please tap "Send a new code" in a minute.')
            ->with(self::devCode($sent, $code));
    }

    /* ---------- sign-up email code ---------- */

    /**
     * A local copy whose email is not set up (MAIL_MAILER=log / array, no Gmail App Password, or Gmail refused)
     * shows the code on the page so sign-up can still be finished. Never on a live site.
     */
    private static function devCode(bool $sent, string $code): array
    {
        $noMail = in_array(config('mail.default'), ['log', 'array'], true) || (config('mail.default') === 'smtp' && ! config('mail.mailers.smtp.password'));

        return app()->environment('local') && (! $sent || $noMail) ? ['dev_code' => $code] : [];
    }

    private static function sendCode(PendingRegistration $p, string $code): bool
    {
        try {
            Notification::route('mail', $p->email)->notify(new RegistrationCode($code, $p->payload['name'] ?? ''));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function pending(Request $request): ?PendingRegistration
    {
        return ($id = $request->session()->get('pending_reg')) ? PendingRegistration::find($id) : null;
    }

    public function showRegisterVerify(Request $request)
    {
        $p = $this->pending($request);
        if (! $p) {
            return redirect()->route('register')->with('error', 'Your sign-up session ended. Please fill in the form again.');
        }

        return view('auth.register-verify', ['p' => $p]);
    }

    public function registerVerify(Request $request)
    {
        $p = $this->pending($request);
        if (! $p) {
            return redirect()->route('register')->with('error', 'Your sign-up session ended. Please fill in the form again.');
        }
        $request->validate(['code' => ['required', 'regex:/^\s*\d{3}\s?\d{3}\s*$/']], ['code.required' => 'Enter the 6-digit code from the email.', 'code.regex' => 'The code is 6 digits.']);
        if ($p->isExpired()) {
            return back()->withErrors(['code' => 'This code has expired. Tap "Send a new code".']);
        }
        if ($p->attempts >= PendingRegistration::MAX_ATTEMPTS) {
            return back()->withErrors(['code' => 'Too many wrong tries. Tap "Send a new code".']);
        }
        if (! $p->matches($request->input('code'))) {
            $p->increment('attempts');
            $left = PendingRegistration::MAX_ATTEMPTS - $p->attempts;

            return back()->withErrors(['code' => 'That code is not right.'.($left > 0 ? ' '.$left.' tries left.' : ' Tap "Send a new code".')]);
        }
        if (User::where('email', $p->email)->exists()) { // someone finished signing up with this email in the meantime
            $p->delete();
            $request->session()->forget('pending_reg');

            return redirect()->route('login')->with('error', 'An account with this email already exists. Please log in.');
        }

        // Code confirmed: now the account is created, already verified.
        $data = $p->payload;
        $u = new User(['name' => $data['name'], 'email' => $p->email,
            'first_name' => $data['first_name'] ?? null, 'middle_name' => $data['middle_name'] ?? null, 'last_name' => $data['last_name'] ?? null,
            'birthday' => $data['birthday'] ?? null, 'gender' => $data['gender'] ?? null, 'phone' => $data['phone'], 'roles' => ['customer'], 'status' => 'active',
            'address' => $data['address'] ?? '', 'address_parts' => $data['address_parts'] ?? null, 'city_code' => $data['city_code'] ?? null]);
        $u->password = $data['password']; // already hashed; the "hashed" cast leaves it as is
        $u->email_verified_at = now();
        $u->save();
        $p->delete();
        $request->session()->forget('pending_reg');

        Notifier::notify($u, 'customer', 'Welcome to PartyRent! 🎉', 'Browse party supplies, or describe your event to our AI planner to get started.', route('ai', [], false));
        Auth::login($u);
        $request->session()->regenerate();
        session(['mode' => 'customer']);

        // Flashed once: the Browse page shows the welcome modal only right after the account is created.
        return redirect()->route('home')->with('welcome_new', $u->firstName());
    }

    public function registerResend(Request $request)
    {
        $p = $this->pending($request);
        if (! $p) {
            return redirect()->route('register')->with('error', 'Your sign-up session ended. Please fill in the form again.');
        }
        if ($wait = $p->secondsUntilResend()) {
            return back()->with('error', 'Please wait '.$wait.' seconds before asking for a new code.');
        }

        $code = $p->newCode();
        $sent = self::sendCode($p, $code);

        return back()->with($sent ? 'success' : 'error', $sent ? 'New code sent to '.$p->email.'. Earlier codes no longer work.' : 'We could not send the email right now. Please try again in a minute.')
            ->with(self::devCode($sent, $code));
    }

    /* ---------- email verification ---------- */

    /** Sends the verification link; returns false (and logs) if the mail server rejects it, so sign-up never 500s. */
    public static function sendVerification(User $u): bool
    {
        try {
            $u->sendEmailVerificationNotification();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function verifyNotice(Request $request)
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route($request->user()->homeRoute(session('mode')))
            : view('auth.verify');
    }

    public function verify(\Illuminate\Foundation\Auth\EmailVerificationRequest $request)
    {
        $request->fulfill(); // marks verified + fires Verified (no-op if already verified)

        return redirect()->route('profile', ['welcome' => 1])->with('success', 'Email verified. You can now rent and check out. 🎉');
    }

    public function resendVerification(Request $request)
    {
        $u = $request->user();
        if ($u->hasVerifiedEmail()) {
            return redirect()->route($u->homeRoute(session('mode')));
        }

        return self::sendVerification($u)
            ? back()->with('success', 'A new verification link was sent to '.$u->email.'.')
            : back()->with('error', 'We could not send the email right now. Please try again in a few minutes.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been logged out.');
    }

    public function showForgot()
    {
        return view('auth.forgot');
    }

    public function sendReset(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        // Same answer whether or not the account exists, so emails can't be enumerated.
        return back()->with('status', 'If an account exists for that email, a reset link is on its way.');
    }

    public function showReset(Request $request, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::defaults()]], \App\Support\AccountRules::messages());
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->setRememberToken(Str::random(60));
            $user->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', 'Password updated. You can now log in.')
            : back()->withErrors(['email' => __($status)]);
    }
}
