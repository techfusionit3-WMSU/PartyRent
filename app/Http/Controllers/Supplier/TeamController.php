<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\ShopStaffs;
use App\Http\Controllers\Controller;
use App\Models\StaffInvite;
use App\Models\User;
use Illuminate\Http\Request;

/** Team & Roles (reference supplier/team.html). Needs the team.manage permission (Organization Admin). */
class TeamController extends Controller
{
    public function index(Request $request)
    {
        $s = $request->user()->shop();
        $scope = ShopStaffs::managerScope($s, $request->user());
        abort_unless($scope, 403);

        return view('supplier.team', [
            's' => $s, 'scope' => $scope,
            'members' => ShopStaffs::members($s, $scope['owner'] ? null : $scope),
            'invites' => StaffInvite::where('supplier_id', $s->id)->latest()->get()->filter(fn ($i) => ShopStaffs::canManage($scope, $i))->values(),
            'branches' => $s->branches()->get()->when($scope['branches'] !== null, fn ($c) => $c->whereIn('id', $scope['branches'])->values()),
            'allBranches' => $s->branches()->get()->keyBy('id'),
        ]);
    }

    /** Live check of the email typed in the Add employee form. */
    public function check(Request $request)
    {
        return response()->json(ShopStaffs::check($request->user()->shop(), (string) $request->input('email')));
    }

    public function store(Request $request)
    {
        $res = ShopStaffs::invite($request->user()->shop(), $request->user(), $request->only('email', 'role', 'roles', 'branches'));
        if (! $res['ok']) {
            return back()->withInput()->withErrors(['team' => $res['error']])->with('error', $res['error']);
        }
        $email = $res['invite']->email;
        if (! $res['sent']) {
            return back()->with('error', 'The invitation was saved but the email to '.$email.' could not be sent. Try Resend in a moment.');
        }

        return back()->with('success', $res['existing']
            ? 'Invitation sent to '.$email.'. They join your team once they accept it from their account.'
            : 'Invitation sent to '.$email.'. They enter the emailed code and set their own password to join.');
    }

    public function resend(Request $request, StaffInvite $invite)
    {
        $res = ShopStaffs::resend($request->user()->shop(), $request->user(), $invite);
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }

        return back()->with($res['sent'] ? 'success' : 'error', $res['sent'] ? 'A new invitation was sent to '.$invite->email.'.' : 'The email could not be sent. Please try again in a moment.');
    }

    public function cancel(Request $request, StaffInvite $invite)
    {
        $res = ShopStaffs::cancel($request->user()->shop(), $request->user(), $invite);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Invitation to '.$invite->email.' cancelled.' : $res['error']);
    }

    public function update(Request $request, User $user)
    {
        $res = ShopStaffs::update($request->user()->shop(), $request->user(), $user, $request->only('role', 'roles', 'perms', 'perms_set', 'status', 'branches', 'branches_set'));
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }
        $msg = match (true) {
            $request->filled('roles') || $request->filled('role') => $user->name.' is now '.$res['member']->label().'.',
            $request->boolean('perms_set') => 'Permissions of '.$user->name.' saved.',
            $request->boolean('branches_set') => 'Branches of '.$user->name.' saved.',
            default => $user->name.' is now '.$res['member']->status.'.',
        };

        return back()->with('success', $msg);
    }
}
