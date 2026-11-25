<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Verifications;
use App\Http\Controllers\Controller;
use App\Models\CustomerVerification;
use Illuminate\Http\Request;

/** Admin review of customer verifications: list by status, open one, approve / reject / ask to resubmit. */
class VerificationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPerm('customers.view'), 403, 'You do not have permission for this page.');
        $tab = in_array($request->query('tab'), ['pending', 'approved', 'attention', 'all'], true) ? $request->query('tab') : 'pending';
        $q = trim((string) $request->query('q', ''));
        $list = CustomerVerification::with('user', 'reviewer')
            ->when($tab === 'pending', fn ($x) => $x->where(fn ($w) => $w->where('status', 'pending')->orWhere('insurance_status', 'submitted')))
            ->when($tab === 'approved', fn ($x) => $x->where('status', 'approved'))
            ->when($tab === 'attention', fn ($x) => $x->whereIn('status', ['rejected', 'resubmit']))
            ->when($q !== '', fn ($x) => $x->whereHas('user', fn ($u) => $u->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")->orderBy('submitted_at')->paginate(25)->withQueryString();
        $open = $request->query('id') ? CustomerVerification::with('user', 'reviewer')->find((int) $request->query('id')) : null;

        return view('admin.verifications', [
            'list' => $list, 'tab' => $tab, 'q' => $q, 'open' => $open,
            'counts' => ['pending' => CustomerVerification::where(fn ($w) => $w->where('status', 'pending')->orWhere('insurance_status', 'submitted'))->count(), 'attention' => CustomerVerification::whereIn('status', ['rejected', 'resubmit'])->count()],
            'canDecide' => $request->user()->hasPerm('customers.manage'),
        ]);
    }

    public function decide(Request $request, CustomerVerification $verification)
    {
        abort_unless($request->user()->hasPerm('customers.manage'), 403);
        $data = $request->validate(['action' => 'required|in:approve,reject,resubmit,insurance', 'reason' => 'nullable|string|max:500', 'insurance' => 'nullable|in:verify,reject,keep']);
        $res = Verifications::decide($verification, $data['action'], $data['reason'] ?? null, $data['insurance'] ?? null, $request->user());

        return redirect()->route('admin.verifications', ['id' => $verification->id])->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Saved. The customer was notified.' : $res['error']);
    }

    public function file(Request $request, CustomerVerification $verification, string $which)
    {
        return Verifications::send($verification, $which, $request->user());
    }
}
