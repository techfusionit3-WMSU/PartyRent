<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Notifier;
use App\Domain\ShopStaffs;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\StartSellingController;
use App\Models\ShopStaff;
use Illuminate\Http\Request;

/** Business account upgrade + staff management (owner only; see ShopStaff::OWNER_ONLY). */
class StaffController extends Controller
{
    public function index(Request $request)
    {
        $s = $request->user()->shop();
        if (! $s->isVerifiedBusiness()) {
            return redirect()->route('supplier.shop')->with('error', 'Staff accounts are for verified Business accounts. Upgrade on this page first.');
        }

        return view('supplier.staff', ['s' => $s, 'staff' => $s->staff()->with('user')->orderBy('role')->get()]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'name' => 'nullable|string|max:120',
            'email' => 'required|email|max:180',
            'role' => 'required|in:'.implode(',', array_keys(ShopStaff::ROLES)),
        ]);
        $res = ShopStaffs::add($request->user()->shop(), $request->user(), $v['name'] ?? '', $v['email'], $v['role']);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return back()->with('success', $res['new']
            ? 'Account created for '.$res['user']->email.'. We emailed them a link to set their password.'
            : $res['user']->name.' was added. They can choose "Work at '.$request->user()->shop()->name.'" in their profile menu.');
    }

    public function update(Request $request, ShopStaff $member)
    {
        abort_unless($member->supplier_id === $request->user()->shop()->id, 404);
        $v = $request->validate(['role' => 'required|in:'.implode(',', array_keys(ShopStaff::ROLES))]);
        ShopStaffs::changeRole($member, $request->user(), $v['role']);

        return back()->with('success', $member->user->name.' is now '.ShopStaff::ROLES[$v['role']][0].'.');
    }

    public function destroy(Request $request, ShopStaff $member)
    {
        abort_unless($member->supplier_id === $request->user()->shop()->id, 404);
        ShopStaffs::remove($member, $request->user());

        return back()->with('success', $member->user->name.' was removed from your staff.');
    }

    /** Personal → Business upgrade (or re-sending documents after a rejection). */
    public function business(Request $request)
    {
        $s = $request->user()->shop();
        $request->merge(['account_type' => 'business']);
        $s->update(StartSellingController::business($request, $s));
        Notifier::notify('admin', 'admin', 'Business documents to check', $s->name.' wants to become a Business account.', route('admin.applications', ['tab' => 'business'], false));
        Notifier::audit('Requested business account', $s->name);

        return back()->with('success', 'Documents sent. PartyRent will check them, usually within 1–2 business days.');
    }
}
