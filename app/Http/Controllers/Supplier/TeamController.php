<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\ShopStaffs;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Team & Roles (reference supplier/team.html). Needs the team.manage permission (Organization Admin). */
class TeamController extends Controller
{
    public function index(Request $request)
    {
        $s = $request->user()->shop();

        return view('supplier.team', [
            's' => $s,
            'members' => ShopStaffs::members($s),
            'tempPw' => Str::lower(Str::random(8)).'A1',
        ]);
    }

    public function store(Request $request)
    {
        $res = ShopStaffs::add($request->user()->shop(), $request->user(), $request->only('name', 'email', 'phone', 'role', 'password'));
        if (! $res['ok']) {
            return back()->withInput()->withErrors(['team' => $res['error']])->with('error', $res['error']);
        }

        $role = \App\Models\ShopStaff::ORG_ROLES[$res['user']->staffOf->role];

        return back()->with('success', $res['created']
            ? $res['user']->name.' added as '.$role.'. Share the temporary password securely; they sign in and choose "Switch to Business Mode".'
            : $res['user']->name.' already has a PartyRent account and was added as '.$role.'. They sign in as usual and choose "Switch to Business Mode".');
    }

    public function update(Request $request, User $user)
    {
        $res = ShopStaffs::update($request->user()->shop(), $request->user(), $user, $request->only('role', 'status'));
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }
        $msg = $request->filled('role') ? 'Role updated.' : $user->name.' is now '.$res['member']->status.'.';

        return back()->with('success', $msg);
    }
}
