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

        return back()->with('success', $res['user']->name.' added as '.\App\Models\ShopStaff::ORG_ROLES[$res['user']->staffOf->role].'. Share the temporary password securely.');
    }

    public function update(Request $request, User $user)
    {
        $res = ShopStaffs::update($request->user()->shop(), $request->user(), $user, $request->only('role', 'status', 'password'));
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }
        $msg = $request->filled('password') ? 'Password reset. Share it securely.' : ($request->filled('role') ? 'Role updated.' : $user->name.' is now '.$user->status.'.');

        return back()->with('success', $msg);
    }
}
