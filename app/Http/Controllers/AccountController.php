<?php

namespace App\Http\Controllers;

use App\Domain\Notifier;
use App\Models\AppNotification;
use App\Support\AddressInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountController extends Controller
{
    /** Customer ⇄ Supplier mode switch. */
    public function switchMode(Request $request)
    {
        $mode = $request->input('mode') === 'supplier' ? 'supplier' : 'customer';
        $u = $request->user();
        if ($mode === 'supplier' && ! $u->isApprovedSupplier()) {
            return back()->with('error', $u->staffOf ? 'The shop you work for is not active right now.' : 'Your supplier profile is not approved yet.');
        }
        session(['mode' => $mode]);
        $label = $mode === 'customer' ? 'Customer' : ($u->isEmployee() ? 'Business' : 'Supplier');

        return redirect()->route($u->homeRoute($mode))->with('success', 'Switched to '.$label.' Mode');
    }

    /** Move between the company modules of the user's roles (Owner / Front Desk / Cashier / Inventory / Field Crew). */
    public function switchModule(Request $request)
    {
        $u = $request->user();
        $m = (string) $request->input('module');
        if (! in_array($m, $u->modules(), true)) {
            return back()->with('error', 'That module is not part of your roles.');
        }
        session(['mode' => 'supplier', 'module' => $m]);

        return redirect()->route(\App\Support\CompanyModules::get($m)['home']);
    }

    public function notifications(Request $request)
    {
        $u = $request->user();
        $list = Notifier::for($u, session('mode'));
        if ($request->query('filter') === 'unread') {
            $list = $list->filter(fn ($n) => ! $n->isReadBy($u->id));
        }

        return view('account.notifications', ['list' => $list, 'filter' => $request->query('filter', 'all'), 'layout' => $this->layoutFor($u)]);
    }

    public function markRead(Request $request)
    {
        $u = $request->user();
        Notifier::markRead(Notifier::for($u, session('mode'))->pluck('id'), $u->id);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'All notifications marked as read.');
    }

    /** Mark one notification read and follow its link (only notifications addressed to this user/role). */
    public function openNotification(Request $request, AppNotification $notification)
    {
        $u = $request->user();
        abort_unless(Notifier::for($u, session('mode'))->contains('id', $notification->id), 404);
        Notifier::markRead([$notification->id], $u->id);
        $link = $notification->link;

        return redirect($link && str_starts_with($link, '/') ? $link : route('notifications'));
    }

    public function account(Request $request)
    {
        return view('account.account', ['u' => $request->user(), 'layout' => $this->layoutFor($request->user())]);
    }

    public function updateProfile(Request $request)
    {
        $u = $request->user();
        $data = $request->validate([
            'name' => 'required|string|min:3|max:120',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($u->id)],
            'phone' => ['required', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'],
            // Staff accounts keep a free-text office address; customers get the structured one used for delivery.
            ...($u->isStaff() ? ['address' => 'required|string|max:255'] : AddressInput::rules('addr')),
        ], array_merge(AddressInput::messages(), ['phone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).', 'email.unique' => 'That email is used by another account.']));
        $attrs = ['name' => trim($data['name']), 'email' => trim($data['email']), 'phone' => trim($data['phone'])];
        $u->fill($u->isStaff() ? $attrs + ['address' => trim($data['address'])] : $attrs + AddressInput::attributes($data['addr']));
        // A customer who changes their email must verify the new address (staff emails are managed by the superadmin).
        $reverify = ! $u->isStaff() && $u->isDirty('email');
        if ($reverify) {
            $u->email_verified_at = null;
        }
        $u->save();
        if (! $u->isStaff()) {
            session()->forget('deliver_to'); // the saved address is the delivery city again
        }
        if ($reverify) {
            \App\Http\Controllers\AuthController::sendVerification($u);

            return redirect()->route('verification.notice')->with('success', 'Profile updated. Please verify your new email address.');
        }

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $u = $request->user();
        $request->validate(['cur' => 'required', 'np' => ['required', 'confirmed', PasswordRule::min(8)]], ['np.confirmed' => 'Passwords do not match.']);
        if (! Hash::check($request->input('cur'), $u->password)) {
            return back()->withErrors(['cur' => 'Current password is incorrect.']);
        }
        $u->update(['password' => $request->input('np')]);

        return back()->with('success', 'Password updated.');
    }

    private function layoutFor($u): string
    {
        return $u->activeRole(session('mode')) === 'customer' ? 'layouts.market' : 'layouts.dash';
    }
}
