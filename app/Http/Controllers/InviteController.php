<?php

namespace App\Http\Controllers;

use App\Domain\ShopStaffs;
use App\Models\StaffInvite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The invited person's side of a team invitation (link from the email). An existing account accepts or
 * declines while signed in; a new email enters the emailed code and sets their own name and password.
 */
class InviteController extends Controller
{
    public function show(Request $request, string $token)
    {
        $inv = StaffInvite::findByToken($token)?->load('supplier', 'inviter');
        if (! $inv || $inv->isExpired()) {
            return view('auth.invite', ['inv' => null, 'token' => $token, 'state' => 'gone']);
        }
        $account = User::where('email', $inv->email)->first();
        $me = $request->user();
        if ($me && Str::lower($me->email) !== $inv->email) {
            $state = 'other';
        } elseif ($account) {
            if (! $me) {
                return redirect()->guest(route('login'))->with('success', 'Log in as '.$inv->email.' to answer the invitation from '.$inv->supplier->name.'.');
            }
            $state = 'accept';
        } else {
            $state = 'new';
        }

        return view('auth.invite', ['inv' => $inv, 'token' => $token, 'state' => $state]);
    }

    public function accept(Request $request, string $token)
    {
        $inv = StaffInvite::findByToken($token);
        if (! $inv) {
            return redirect()->route('home')->with('error', 'This invitation is no longer available.');
        }
        $res = ShopStaffs::accept($inv, $request->user());
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }

        return $this->joined($request->user(), $res);
    }

    public function decline(Request $request, string $token)
    {
        $inv = StaffInvite::findByToken($token);
        if (! $inv) {
            return redirect()->route('home')->with('error', 'This invitation is no longer available.');
        }
        $res = ShopStaffs::decline($inv, $request->user());

        return redirect()->route('home')->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'You declined the invitation from '.$res['supplier']->name.'.' : $res['error']);
    }

    public function join(Request $request, string $token)
    {
        $inv = StaffInvite::findByToken($token);
        if (! $inv) {
            return redirect()->route('login')->with('error', 'This invitation is no longer available. Ask the shop to send it again.');
        }
        $data = $request->validate([
            'code' => 'required|string',
            ...\App\Support\AccountRules::name(), ...\App\Support\AccountRules::birthday(), ...\App\Support\AccountRules::gender(),
            'phone' => 'required|string|max:30',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], \App\Support\AccountRules::messages());
        $res = ShopStaffs::joinNew($inv, $data);
        if (! $res['ok']) {
            return back()->withInput($request->except('password', 'password_confirmation', 'code'))->withErrors([$res['field'] ?? 'invite' => $res['error']]);
        }
        Auth::login($res['user']);
        $request->session()->regenerate();

        return $this->joined($res['user'], $res);
    }

    /** Straight into Business Mode for the shop they just joined. */
    private function joined(User $u, array $res)
    {
        $u = $u->fresh();
        $msg = 'You joined '.$res['supplier']->name.' as '.$res['role'].'. 🎉';
        if ($u->isApprovedSupplier()) {
            session(['mode' => 'supplier']);

            return redirect()->route($u->homeRoute('supplier'))->with('success', $msg.' You are in Business Mode; switch back to Customer Mode any time from your account menu.');
        }
        session(['mode' => 'customer']);

        return redirect()->route('home')->with('success', $msg.' Business Mode opens once the shop is active.');
    }
}
