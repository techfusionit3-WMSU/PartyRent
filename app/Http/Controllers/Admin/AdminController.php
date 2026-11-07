<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Finance;
use App\Domain\Notifier;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    private function need(Request $r, string $perm): void
    {
        abort_unless($r->user()->hasPerm($perm), 403, 'You do not have permission for this page.');
    }

    public function dashboard(Request $request)
    {
        Rentals::scanOverdue();
        $reqs = RentalRequest::with('payments')->get();
        $ver = Payment::where('status', 'verified')->get();

        return view('admin.dashboard', [
            'users' => User::count(), 'customers' => User::whereJsonContains('roles', 'customer')->count(),
            'suppliers' => Supplier::approved()->count(), 'pendingApps' => Supplier::where('status', 'pending')->count(),
            'listings' => Product::where('status', 'published')->count(),
            'active' => $reqs->filter->isActive()->count(), 'pending' => $reqs->where('status', 'pending')->count(),
            'completed' => $reqs->where('status', 'completed')->count(),
            'overdue' => $reqs->filter(fn ($r) => $r->isActive() && $r->fin()['isOverdue'])->count(),
            'gmv' => $ver->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $ver->where('type', 'refund')->sum('amount'),
            'commission' => (float) setting('commissionPct', 5),
            'openComplaints' => Complaint::where('status', '!=', 'resolved')->count(),
            'byStatus' => collect(RentalRequest::STATUS)->map(fn ($v, $k) => ['label' => $v[0], 'value' => $reqs->where('status', $k)->count()])->values(),
            'audit' => AuditLog::with('actor')->orderByDesc('created_at')->take(8)->get(),
        ]);
    }

    public function applications(Request $request)
    {
        $this->need($request, 'applications.review');
        $tab = in_array($request->query('tab'), ['pending', 'approved', 'rejected'], true) ? $request->query('tab') : 'pending';

        return view('admin.applications', ['list' => Supplier::where('status', $tab)->with('owner')->latest('applied_at')->get(), 'tab' => $tab, 'counts' => Supplier::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')]);
    }

    public function reviewApplication(Request $request, Supplier $supplier)
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'nullable|string|max:500']);
        $res = Rentals::reviewApplication($supplier, $data['decision'] === 'approve', $data['reason'] ?? null, $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $supplier->name.' '.($data['decision'] === 'approve' ? 'approved' : 'rejected').'. The applicant was notified.' : $res['error']);
    }

    public function users(Request $request)
    {
        abort_unless($request->user()->hasPerm('customers.view') || $request->user()->hasPerm('suppliers.view'), 403);
        $q = trim((string) $request->query('q', ''));
        $list = User::with('supplier')->when($q, fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))->orderBy('name')->paginate(30)->withQueryString();

        return view('admin.users', ['list' => $list, 'q' => $q]);
    }

    public function userStatus(Request $request, User $user)
    {
        $me = $request->user();
        $data = $request->validate(['status' => 'required|in:active,suspended', 'reason' => 'nullable|string|max:255']);
        $perm = $user->supplier ? 'suppliers.manage' : 'customers.manage';
        if (! $me->hasPerm($perm) || $user->isStaff() || $user->id === $me->id) {
            return back()->with('error', 'You do not have permission to change this account.');
        }
        $user->update(['status' => $data['status']]);
        Notifier::audit($data['status'] === 'suspended' ? 'Suspended account' : 'Reactivated account', $user->name.' ('.$user->email.')'.($data['reason'] ? ' — '.$data['reason'] : ''), $me);

        return back()->with('success', $user->name.' is now '.$data['status'].'.');
    }

    public function transactions(Request $request)
    {
        $this->need($request, 'transactions.view');
        $status = array_key_exists((string) $request->query('status'), RentalRequest::STATUS) ? $request->query('status') : null;
        $list = RentalRequest::with(['customer', 'supplier', 'payments'])->when($status, fn ($x) => $x->where('status', $status))->latest()->paginate(25)->withQueryString();

        return view('admin.transactions', ['list' => $list, 'status' => $status]);
    }

    /* ---------- listings ---------- */

    public function listings(Request $request)
    {
        $this->need($request, 'listings.view');
        $view = $request->query('view') === 'packages' ? 'packages' : 'items';
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        if ($view === 'packages') {
            $list = \App\Models\Package::with('supplier')->when($q, fn ($x) => $x->where('name', 'like', "%$q%"))->latest()->paginate(30)->withQueryString();
        } else {
            $list = Product::with(['supplier', 'category'])->where('status', '!=', 'archived')
                ->when($q, fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%$q%"))))
                ->when(in_array($status, ['published', 'draft', 'hidden'], true), fn ($x) => $x->where('status', $status))
                ->orderBy('name')->paginate(30)->withQueryString();
        }

        return view('admin.listings', ['list' => $list, 'view' => $view, 'q' => $q, 'status' => $status]);
    }

    public function listingStatus(Request $request, Product $product)
    {
        $this->need($request, 'listings.manage');
        $data = $request->validate(['status' => 'required|in:published,hidden', 'reason' => 'nullable|string|max:255']);
        $product->update(['status' => $data['status']]);
        $hidden = $data['status'] === 'hidden';
        $data['reason'] ??= null;
        Notifier::notify($product->supplier->owner_id, 'supplier', $hidden ? 'Listing hidden by PartyRent' : 'Listing restored', $product->name.($hidden ? ' was hidden from the marketplace'.($data['reason'] ? ': '.$data['reason'] : '.') : ' is visible again.'), route('supplier.products', [], false));
        Notifier::audit($hidden ? 'Hid listing' : 'Restored listing', $product->name.' · '.$product->supplier->name.($data['reason'] ? ' — '.$data['reason'] : ''));

        return back()->with('success', $product->name.($hidden ? ' hidden.' : ' restored.'));
    }

    public function packageStatus(Request $request, \App\Models\Package $package)
    {
        $this->need($request, 'listings.manage');
        $package->update(['active' => ! $package->active]);
        Notifier::audit($package->active ? 'Restored package' : 'Hid package', $package->name.' · '.$package->supplier->name);

        return back()->with('success', $package->name.($package->active ? ' restored.' : ' hidden.'));
    }

    /* ---------- categories ---------- */

    public function categories(Request $request)
    {
        $this->need($request, 'categories.manage');

        return view('admin.categories', ['list' => \App\Models\Category::withCount('products')->orderBy('sort')->get(), 'icons' => array_keys((require app_path('Support/icons.php'))['art'])]);
    }

    public function storeCategory(Request $request)
    {
        return $this->saveCategory($request, null);
    }

    public function updateCategory(Request $request, \App\Models\Category $category)
    {
        return $this->saveCategory($request, $category);
    }

    private function saveCategory(Request $request, ?\App\Models\Category $category)
    {
        $this->need($request, 'categories.manage');
        $data = $request->validate([
            'name' => 'required|string|max:60', 'icon' => 'required|string|max:40',
            'grad_from' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'grad_to' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'active' => 'nullable|boolean',
        ]);
        $data['active'] = $request->boolean('active');
        if ($category) {
            $category->update($data);
            Notifier::audit('Updated category', $category->name);
        } else {
            $id = str($data['name'])->slug()->limit(40, '')->toString() ?: 'cat';
            if (\App\Models\Category::find($id)) {
                return back()->with('error', 'A category with that name already exists.');
            }
            \App\Models\Category::create($data + ['id' => $id, 'sort' => (int) \App\Models\Category::max('sort') + 1]);
            Notifier::audit('Created category', $data['name']);
        }

        return back()->with('success', 'Category saved.');
    }

    /* ---------- complaints ---------- */

    public function complaints(Request $request)
    {
        $this->need($request, 'complaints.manage');
        $tab = in_array($request->query('tab'), ['open', 'in_review', 'resolved', 'all'], true) ? $request->query('tab') : 'open';
        $all = Complaint::with(['reporter', 'againstSupplier', 'rentalRequest'])->latest()->get();

        return view('admin.complaints', [
            'list' => $tab === 'all' ? $all : $all->where('status', $tab), 'tab' => $tab,
            'counts' => ['open' => $all->where('status', 'open')->count(), 'in_review' => $all->where('status', 'in_review')->count(), 'resolved' => $all->where('status', 'resolved')->count(), 'all' => $all->count()],
            'staff' => User::whereIn('id', $all->flatMap(fn ($c) => collect($c->updates)->pluck('by'))->unique())->pluck('name', 'id'),
            'customers' => User::whereJsonContains('roles', 'customer')->orderBy('name')->get(['id', 'name', 'email']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** "Log an issue": staff file a report on behalf of a customer (phone, email, walk-in). */
    public function storeComplaint(Request $request)
    {
        $this->need($request, 'complaints.manage');
        $data = $request->validate([
            'reporter_id' => 'required|exists:users,id', 'against_supplier_id' => 'nullable|exists:suppliers,id',
            'request_code' => 'nullable|string|exists:rental_requests,code',
            'subject' => 'required|string|max:160', 'body' => 'required|string|max:2000', 'priority' => 'required|in:low,normal,high',
        ]);
        $next = (int) Complaint::selectRaw('max(cast(substr(code, 5) as unsigned)) as n')->value('n') + 1;
        $c = Complaint::create([
            'code' => 'CMP-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT),
            'reporter_id' => $data['reporter_id'], 'against_supplier_id' => $data['against_supplier_id'] ?? null,
            'rental_request_id' => ! empty($data['request_code']) ? RentalRequest::where('code', $data['request_code'])->value('id') : null,
            'subject' => $data['subject'], 'body' => $data['body'], 'priority' => $data['priority'], 'status' => 'open', 'updates' => [],
        ]);
        Notifier::audit('Logged complaint', $c->code.' · '.$c->subject);

        return redirect()->route('admin.complaints', ['tab' => 'open'])->with('success', $c->code.' logged.');
    }

    public function updateComplaint(Request $request, Complaint $complaint)
    {
        $this->need($request, 'complaints.manage');
        $data = $request->validate(['status' => 'required|in:open,in_review,resolved', 'note' => 'required|string|max:1000']);
        $complaint->update(['status' => $data['status'], 'updates' => array_merge($complaint->updates ?? [], [['at' => now()->toIso8601String(), 'by' => $request->user()->id, 'note' => $data['note']]])]);
        Notifier::notify($complaint->reporter_id, 'customer', 'Update on your report '.$complaint->code, ucfirst(str_replace('_', ' ', $data['status'])).': '.$data['note'], $complaint->rentalRequest ? route('rentals.show', $complaint->rentalRequest, false) : '');
        Notifier::audit('Updated complaint', $complaint->code.' → '.$data['status']);

        return back()->with('success', $complaint->code.' updated and the customer was notified.');
    }

    /* ---------- reports ---------- */

    private function reportData(Request $request): array
    {
        $rg = \App\Support\Report::range($request);
        $reqs = RentalRequest::with(['payments', 'supplier', 'customer'])->get();
        $inRange = $reqs->filter(fn ($r) => $r->created_at->toDateString() >= $rg['from'] && $r->created_at->toDateString() <= $rg['to']);
        $ver = Payment::where('status', 'verified')->with('rentalRequest.supplier')->get();
        $net = fn ($l) => $l->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $l->where('type', 'refund')->sum('amount');

        return compact('rg', 'reqs', 'inRange', 'ver', 'net');
    }

    public function reports(Request $request)
    {
        $this->need($request, 'reports.view');
        extract($this->reportData($request));
        $months = \App\Support\Report::months(6);
        $period = $ver->filter(fn ($p) => $p->date >= $rg['from'] && $p->date <= $rg['to']);
        $gmv = $net($period);

        return view('admin.reports', [
            'rg' => $rg, 'gmv' => $gmv, 'commission' => $gmv * (float) setting('commissionPct', 5) / 100, 'inRange' => $inRange,
            'newUsers' => User::whereBetween('created_at', [$rg['from'].' 00:00:00', $rg['to'].' 23:59:59'])->count(),
            'newSuppliers' => Supplier::whereBetween('approved_at', [$rg['from'].' 00:00:00', $rg['to'].' 23:59:59'])->count(),
            'acceptRate' => ($decided = $inRange->whereNotIn('status', ['pending', 'cancelled'])->count()) ? round($inRange->whereNotIn('status', ['pending', 'cancelled', 'declined'])->count() / $decided * 100) : null,
            'monthlyReq' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $reqs->filter(fn ($r) => $r->created_at->format('Y-m') === $m['key'])->count()], $months),
            'monthlyGmv' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $net($ver->filter(fn ($p) => str_starts_with($p->date, $m['key'])))], $months),
            'bySupplier' => $period->whereIn('type', Finance::RENTAL_TYPES)->groupBy(fn ($p) => $p->rentalRequest->supplier->name)->map(fn ($g, $n) => ['label' => $n, 'value' => $g->sum('amount')])->sortByDesc('value')->values(),
            'byEvent' => $inRange->groupBy('event_type')->map(fn ($g, $n) => ['label' => $n, 'value' => $g->count()])->sortByDesc('value')->values(),
            'byCity' => $inRange->groupBy(fn ($r) => $r->supplier->city)->map(fn ($g, $n) => ['label' => $n, 'value' => $g->count()])->sortByDesc('value')->values(),
            // growth & activity (prototype "Platform Reports")
            'userGrowth' => $this->cumulative(User::pluck('created_at')->map(fn ($d) => $d?->format('Y-m')), $months),
            'supplierGrowth' => $this->cumulative(Supplier::approved()->pluck('approved_at')->map(fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('Y-m') : null), $months),
            'statusMix' => collect(['Pending' => ['pending'], 'Active' => Finance::ACTIVE, 'Completed' => ['completed'], 'Declined' => ['declined'], 'Cancelled' => ['cancelled']])
                ->map(fn ($st, $l) => ['label' => $l, 'value' => $reqs->whereIn('status', $st)->count()])->values(),
            'staffActivity' => AuditLog::whereIn('role', ['admin', 'superadmin', 'cashier'])->with('actor')->get()
                ->groupBy(fn ($a) => $a->actor->name ?? 'System')->map(fn ($g, $n) => ['label' => $n, 'value' => $g->count()])->sortByDesc('value')->values(),
        ]);
    }

    /** Running total per month: everything before the window, then each month's additions. */
    private function cumulative($monthKeys, array $months): array
    {
        $keys = $monthKeys->filter();
        $run = $keys->filter(fn ($k) => $k < $months[0]['key'])->count();

        return array_map(function ($m) use ($keys, &$run) {
            $run += $keys->filter(fn ($k) => $k === $m['key'])->count();

            return ['label' => $m['label'], 'value' => $run];
        }, $months);
    }

    public function reportsCsv(Request $request)
    {
        $this->need($request, 'reports.view');
        extract($this->reportData($request));
        $rows = [['Request', 'Created', 'Customer', 'Supplier', 'City', 'Event type', 'Start', 'End', 'Status', 'Total', 'Verified paid', 'Balance', 'Payment status']];
        foreach ($inRange->sortBy('created_at') as $r) {
            $f = $r->fin();
            $rows[] = [$r->code, $r->created_at->toDateString(), $r->customer->name, $r->supplier->name, $r->supplier->city, $r->event_type, $r->start_date, $r->end_date, RentalRequest::statusLabel($r->status), $f['payable'], $f['paidRental'], $f['balance'], $f['status']];
        }

        return \App\Support\Report::csv('partyrent-platform-'.$rg['from'].'-to-'.$rg['to'].'.csv', $rows);
    }

    public function audit(Request $request)
    {
        abort_unless($request->user()->hasRole('superadmin') || $request->user()->hasPerm('reports.view'), 403);

        return view('admin.audit', ['list' => AuditLog::with('actor')->orderByDesc('created_at')->orderByDesc('id')->paginate(40)]);
    }
}
