<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Finance;
use App\Domain\Notifier;
use App\Domain\PayMongo;
use App\Domain\Settings;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\CartLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** Superadmin-only pages (route group is guarded by role:superadmin). */
class SuperController extends Controller
{
    public function dashboard()
    {
        \App\Domain\Rentals::scanOverdue();
        $users = User::all();
        $reqs = RentalRequest::with('payments')->get();
        $ver = Payment::where('status', 'verified')->get();
        $gross = $ver->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $ver->where('type', 'refund')->sum('amount');
        $active = $reqs->filter->isActive();
        $months = \App\Support\Report::months(6);
        $counted = $reqs->whereNotIn('status', ['pending', 'declined', 'cancelled']);

        return view('super.dashboard', [
            'users' => $users->count(), 'inactive' => $users->where('status', '!=', 'active')->count(),
            'customers' => $users->filter(fn ($u) => $u->hasRole('customer'))->count(),
            'alsoSuppliers' => Supplier::whereNotNull('owner_id')->count(),
            'suppliers' => Supplier::approved()->count(), 'pendingApps' => Supplier::where('status', 'pending')->count(),
            'admins' => $users->filter(fn ($u) => $u->hasRole('admin'))->count(), 'cashiers' => $users->filter(fn ($u) => $u->hasRole('cashier'))->count(),
            'requests' => $reqs->count(), 'active' => $active->count(),
            'gross' => $gross, 'commission' => $gross * (float) setting('commissionPct', 5) / 100,
            'outstanding' => $active->sum(fn ($r) => $r->fin()['balance']), 'overdue' => $active->filter(fn ($r) => $r->fin()['isOverdue'])->count(),
            'trend' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $reqs->filter(fn ($r) => $r->created_at->format('Y-m') === $m['key'])->count()], $months),
            'collections' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $ver->whereIn('type', Finance::RENTAL_TYPES)->filter(fn ($p) => str_starts_with($p->date, $m['key']))->sum('amount')], $months),
            'perf' => Supplier::approved()->orderByDesc('rating')->get()->map(fn ($s) => [
                'model' => $s,
                'rentals' => $reqs->where('supplier_id', $s->id)->whereNotIn('status', ['pending', 'declined'])->count(),
                'value' => $counted->where('supplier_id', $s->id)->sum(fn ($r) => $r->fin()['payable']),
            ]),
            'audit' => AuditLog::with('actor')->orderByDesc('created_at')->take(7)->get(),
        ]);
    }

    public function monitoring()
    {
        $T = today_iso();
        $reqs = RentalRequest::with('payments')->whereIn('status', array_merge(Finance::ACTIVE, ['pending']))->get();
        $dbOk = true;
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        $stalePending = RentalRequest::where('status', 'pending')->where('created_at', '<', now()->subDays(3))->count();
        $staleProofs = Payment::where('status', 'pending')->where('submitted_at', '<', now()->subDays(2))->count();
        $conflicts = $reqs->where('status', 'pending')->filter(fn ($r) => inv()->check($r->lines, $r->id)['bad'])->count();
        $lateReturns = RentalRequest::whereIn('status', ['ongoing', 'return_pending'])->where('end_date', '<', add_days($T, -1))->count();

        return view('super.monitoring', [
            'checks' => [
                ['Database connection', $dbOk, $dbOk ? 'Connected ('.config('database.default').')' : 'Unreachable'],
                ['Storage link', is_link(public_path('storage')) || is_dir(public_path('storage')), 'public/storage → storage/app/public'],
                ['Debug mode off', ! config('app.debug'), config('app.debug') ? 'APP_DEBUG=true — turn off in production' : 'OK'],
                ['Mail delivery', config('mail.default') !== 'log', 'Mailer: '.config('mail.default').(config('mail.default') === 'log' ? ' (password-reset emails only go to the log)' : '')],
                ['AI assistant', (bool) setting('aiEnabled', true), setting('aiEnabled', true) ? 'Enabled' : 'Turned off in settings'],
            ],
            'queues' => [
                ['Pending requests older than 3 days', $stalePending, route('admin.transactions', ['status' => 'pending'])],
                ['Payment proofs waiting over 2 days', $staleProofs, route('cashier.verification')],
                ['Pending requests with stock conflicts', $conflicts, route('admin.transactions', ['status' => 'pending'])],
                ['Overdue payments', $reqs->filter(fn ($r) => $r->isActive() && $r->fin()['isOverdue'])->count(), route('cashier.balances', ['tab' => 'overdue'])],
                ['Late returns (past end date)', $lateReturns, route('admin.transactions', ['status' => 'ongoing'])],
                ['Supplier applications waiting', Supplier::where('status', 'pending')->count(), route('admin.applications')],
            ],
            'counts' => [
                'Users' => User::count(), 'Suspended users' => User::where('status', 'suspended')->count(), 'Suppliers' => Supplier::count(),
                'Products' => Product::count(), 'Rental requests' => RentalRequest::count(), 'Payments' => Payment::count(),
                'Open cart lines' => CartLine::count(), 'Notifications' => AppNotification::count(), 'Audit entries' => AuditLog::count(),
            ],
            'signins' => AuditLog::where('action', 'Signed in')->with('actor')->orderByDesc('created_at')->take(10)->get(),
        ]);
    }

    public function users(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $role = in_array($request->query('role'), ['customer', 'cashier', 'admin', 'superadmin'], true) ? $request->query('role') : null;
        $list = User::with('supplier')
            ->when($q, fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))
            ->when($role, fn ($x) => $x->whereJsonContains('roles', $role))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('super.users', ['list' => $list, 'q' => $q, 'role' => $role]);
    }

    public function userStatus(Request $request, User $user)
    {
        $data = $request->validate(['status' => 'required|in:active,suspended']);
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot change your own account status.');
        }
        if ($user->hasRole('superadmin') && $data['status'] === 'suspended' && User::whereJsonContains('roles', 'superadmin')->where('status', 'active')->count() <= 1) {
            return back()->with('error', 'At least one active superadmin is required.');
        }
        $user->update(['status' => $data['status']]);
        Notifier::audit($data['status'] === 'suspended' ? 'Suspended account' : 'Reactivated account', $user->name.' ('.$user->email.')');

        return back()->with('success', $user->name.' is now '.$data['status'].'.');
    }

    /* ---------- administrators (admins & cashiers) ---------- */

    public function admins()
    {
        return view('super.admins', [
            'staff' => User::where(fn ($q) => $q->whereJsonContains('roles', 'admin')->orWhereJsonContains('roles', 'cashier')->orWhereJsonContains('roles', 'superadmin'))->orderBy('name')->get(),
            'adminPerms' => User::ADMIN_PERMS, 'cashierPerms' => User::CASHIER_PERMS,
            'defaults' => ['admin' => Settings::get('permissions.admin', array_keys(User::ADMIN_PERMS)), 'cashier' => Settings::get('permissions.cashier', array_keys(User::CASHIER_PERMS))],
        ]);
    }

    public function storeAdmin(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:120', 'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:40', 'role' => 'required|in:admin,cashier', 'password' => ['required', PasswordRule::min(10)],
        ]);
        $u = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'phone' => $data['phone'] ?? '', 'address' => 'PartyRent HQ', 'roles' => [$data['role']], 'status' => 'active', 'password' => $data['password']]);
        $u->forceFill(['email_verified_at' => now()])->save(); // staff accounts are vouched for by the superadmin
        Notifier::audit('Created '.$data['role'].' account', $u->name.' ('.$u->email.')');

        return back()->with('success', $u->name.' can now sign in as '.$data['role'].'. Share the temporary password securely and ask them to change it.');
    }

    /** Per-user permissions. "Use role defaults" stores null so later default changes apply. */
    public function updateAdmin(Request $request, User $user)
    {
        abort_unless($user->hasRole('admin') || $user->hasRole('cashier'), 404);
        $isAdmin = $user->hasRole('admin');
        $allowed = array_keys($isAdmin ? User::ADMIN_PERMS : User::CASHIER_PERMS);
        $perms = $request->boolean('use_defaults') ? null : array_values(array_intersect((array) $request->input('perms', []), $allowed));
        $before = $isAdmin ? $user->admin_perms : $user->cashier_perms;
        $user->update([$isAdmin ? 'admin_perms' : 'cashier_perms' => $perms]);
        $desc = $perms === null ? 'using role defaults' : implode(', ', $perms);
        Notifier::audit('Updated permissions', $user->name.': '.$desc.($before !== null ? ' (was: '.implode(', ', $before).')' : ''));

        return back()->with('success', 'Permissions for '.$user->name.' saved.');
    }

    /* ---------- default role permissions ---------- */

    public function roles()
    {
        return view('super.roles', [
            'adminPerms' => User::ADMIN_PERMS, 'cashierPerms' => User::CASHIER_PERMS,
            'defaults' => ['admin' => Settings::get('permissions.admin', array_keys(User::ADMIN_PERMS)), 'cashier' => Settings::get('permissions.cashier', array_keys(User::CASHIER_PERMS))],
            'customised' => User::whereNotNull('admin_perms')->orWhereNotNull('cashier_perms')->get(),
        ]);
    }

    public function updateRoles(Request $request)
    {
        $admin = array_values(array_intersect((array) $request->input('admin', []), array_keys(User::ADMIN_PERMS)));
        $cashier = array_values(array_intersect((array) $request->input('cashier', []), array_keys(User::CASHIER_PERMS)));
        Settings::set('permissions.admin', $admin);
        Settings::set('permissions.cashier', $cashier);
        Notifier::audit('Updated default role permissions', 'Admin: '.(implode(', ', $admin) ?: 'none').' · Cashier: '.(implode(', ', $cashier) ?: 'none'));

        return back()->with('success', 'Default permissions saved. Staff using role defaults get them immediately.');
    }

    /* ---------- system settings ---------- */

    public function settings()
    {
        return view('super.settings', [
            's' => array_merge(Settings::DEFAULTS, array_intersect_key(Settings::all(), Settings::DEFAULTS)),
            'accountCount' => \App\Models\PayAccount::where('active', true)->count(),
            'paymongo' => ['enabled' => PayMongo::enabled(), 'live' => PayMongo::liveMode(), 'webhook' => (string) config('services.paymongo.webhook_secret') !== '', 'methods' => config('services.paymongo.methods')],
        ]);
    }

    public function updateSettings(Request $request)
    {
        $v = $request->validate([
            'platformName' => 'required|string|max:60', 'supportEmail' => 'required|email', 'supportPhone' => 'required|string|max:40',
            'overdueGraceDays' => 'required|integer|min:0|max:30', 'maxRentalDays' => 'required|integer|min:1|max:90', 'commissionPct' => 'required|numeric|min:0|max:50',
        ]);
        $v['requireSupplierApproval'] = $request->boolean('requireSupplierApproval');
        $v['aiEnabled'] = $request->boolean('aiEnabled');
        $changes = [];
        foreach ($v as $k => $val) {
            $old = Settings::get($k);
            if ($old != $val) {
                $changes[] = $k.': '.json_encode($old).' → '.json_encode($val);
                Settings::set($k, is_numeric($val) && ! is_bool($val) ? $val + 0 : $val);
            }
        }
        if ($changes) {
            Notifier::audit('Changed platform settings', implode('; ', $changes));
        }

        return back()->with('success', $changes ? 'Settings saved.' : 'No changes.');
    }
}
