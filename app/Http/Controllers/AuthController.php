<?php

namespace App\Http\Controllers;

use App\Domain\Notifier;
use App\Models\User;
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
            'name' => 'required|string|min:3|max:120',
            'email' => 'required|email|max:180|unique:users,email',
            'phone' => ['required', 'string', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'],
            'address' => 'required|string|max:255',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'terms' => 'accepted',
        ], ['phone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).']);
        $u = User::create([
            'name' => trim($data['name']), 'email' => strtolower(trim($data['email'])), 'phone' => trim($data['phone']),
            'address' => trim($data['address']), 'password' => $data['password'], 'roles' => ['customer'], 'status' => 'active',
        ]);
        Notifier::notify($u, 'customer', 'Welcome to PartyRent! 🎉', 'Browse party supplies, or describe your event to our AI planner to get started.', route('ai', [], false));
        Auth::login($u);
        $request->session()->regenerate();
        session(['mode' => 'customer']);

        return redirect()->route('profile', ['welcome' => 1]);
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
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(8)]]);
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
