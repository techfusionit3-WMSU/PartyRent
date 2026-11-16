<?php

namespace App\Http\Controllers;

use App\Domain\Shortfalls;
use App\Models\Product;
use App\Models\ShortfallCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Shortfall / dispute cases (change list C12). The case page is shared: the booking's customer, the shop
 * (members who can view orders; answering needs orders.manage) and PartyRent support (complaints.manage).
 */
class CaseController extends Controller
{
    /** customer | supplier | admin, or null when the user has no part in the case. */
    private function roleIn(Request $request, ShortfallCase $c): ?string
    {
        $u = $request->user();
        $active = $u->activeRole(session('mode'));
        $isCustomer = $u->id === $c->customer_id;
        $isShop = $u->shop()?->id === $c->supplier_id && ($u->supplier?->id === $c->supplier_id || $u->orgCan('orders.view'));
        $isAdmin = $u->isStaff() && $u->hasPerm('complaints.manage');
        if ($active === 'customer' && $isCustomer) {
            return 'customer';
        }
        if ($active === 'supplier' && $isShop) {
            return 'supplier';
        }
        if ($isAdmin) {
            return 'admin';
        }

        return $isCustomer ? 'customer' : ($isShop ? 'supplier' : null);
    }

    private function role(Request $request, ShortfallCase $c, array $allowed): string
    {
        $role = $this->roleIn($request, $c);
        abort_unless($role && in_array($role, $allowed, true), 403);
        if ($role === 'supplier' && $request->isMethod('post') && $request->user()->supplier?->id !== $c->supplier_id) {
            abort_unless($request->user()->orgCan('orders.manage'), 403);
        }

        return $role;
    }

    private function back(array $res, string $ok)
    {
        return $res['ok'] ? back()->with('success', $ok) : back()->withInput()->with('error', $res['error']);
    }

    /* ---------- supplier: report + list ---------- */

    public function supplierIndex(Request $request)
    {
        $s = $request->user()->shop();
        Shortfalls::escalateDue();

        return view('supplier.shortfalls', [
            'cases' => ShortfallCase::where('supplier_id', $s->id)->with(['rentalRequest', 'customer', 'product'])->orderByRaw("status = 'resolved'")->orderByDesc('id')->get(),
            'products' => Product::where('supplier_id', $s->id)->where('listing_type', 'rent')->whereNotIn('status', ['deleted', 'archived'])->orderBy('name')->get(),
        ]);
    }

    public function report(Request $request)
    {
        $v = $request->validate([
            'product_id' => 'required|integer', 'qty' => 'required|integer|min:1',
            'start_date' => 'required|date|after_or_equal:today', 'end_date' => 'nullable|date|after_or_equal:start_date',
            'reason' => 'required|string|min:5|max:1000', 'photo' => 'nullable|image|max:8192',
        ], ['start_date.after_or_equal' => 'The first affected date must be today or later.']);
        $end = $v['end_date'] ?? $v['start_date'];
        if (count(date_range($v['start_date'], $end)) > 60) {
            return back()->withInput()->with('error', 'Report at most 60 days at a time.');
        }
        $photo = $request->file('photo')?->store('cases', 'local');
        $res = Shortfalls::report($request->user(), $request->user()->shop(), [
            'product_id' => $v['product_id'], 'qty' => $v['qty'], 'dates' => date_range($v['start_date'], $end), 'reason' => $v['reason'], 'photo' => $photo,
        ]);
        if (! $res['ok']) {
            if ($photo) {
                Storage::disk('local')->delete($photo);
            }

            return back()->withInput()->with('error', $res['error']);
        }
        $n = count($res['cases']);

        return redirect()->route('supplier.shortfalls')->with('success', $n
            ? 'Reported. '.$n.' booking'.($n > 1 ? 's are' : ' is').' affected ('.collect($res['cases'])->map(fn ($c) => $c->rentalRequest->code)->implode(', ').'); the customers were notified. Open each case to propose a solution.'
            : 'Reported. The units are out of stock on those dates, and your spare stock still covers every accepted booking.');
    }

    /* ---------- shared case page ---------- */

    public function show(Request $request, ShortfallCase $case)
    {
        $role = $this->role($request, $case, ['customer', 'supplier', 'admin']);
        Shortfalls::escalateDue();
        $case->refresh()->load(['events.user', 'rentalRequest', 'supplier', 'customer', 'product']);

        return view('cases.show', [
            'c' => $case, 'role' => $role, 'open' => $case->proposal(),
            'layout' => $role === 'customer' ? 'layouts.market' : 'layouts.dash',
            'subs' => $role === 'supplier' ? Product::where('supplier_id', $case->supplier_id)->where('listing_type', 'rent')->where('status', 'active')->where('id', '!=', $case->product_id)->orderBy('name')->get() : collect(),
            'shortValue' => Shortfalls::shortValue($case),
        ]);
    }

    public function propose(Request $request, ShortfallCase $case)
    {
        $role = $this->role($request, $case, ['customer', 'supplier']);
        $v = $request->validate([
            'type' => 'required|string', 'credit' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:2000',
            'substitute_id' => 'nullable|integer', 'deadline_hours' => 'nullable|integer|min:1|max:168',
        ]);

        return $this->back(Shortfalls::propose($case, $request->user(), $role, $v), $role === 'customer' ? 'Counter-offer sent.' : 'Proposal sent to the customer.');
    }

    public function respond(Request $request, ShortfallCase $case)
    {
        $role = $this->role($request, $case, ['customer', 'supplier']);
        $v = $request->validate(['answer' => 'required|in:accept,decline', 'note' => 'nullable|string|max:2000']);

        return $this->back(Shortfalls::respond($case, $request->user(), $role, $v['answer'], (string) ($v['note'] ?? '')), $v['answer'] === 'accept' ? 'Agreed. The booking has been updated.' : 'Proposal declined.');
    }

    public function message(Request $request, ShortfallCase $case)
    {
        $role = $this->role($request, $case, ['customer', 'supplier', 'admin']);
        $v = $request->validate(['body' => 'required|string|max:2000']);

        return $this->back(Shortfalls::message($case, $request->user(), $role, $v['body']), 'Message sent.');
    }

    public function settle(Request $request, ShortfallCase $case)
    {
        $this->role($request, $case, ['admin']);
        $v = $request->validate(['proposal' => 'nullable|integer', 'note' => 'nullable|string|max:2000']);

        return $this->back(Shortfalls::settle($case, $request->user(), $v['proposal'] ?? null, (string) ($v['note'] ?? '')), 'Case settled.');
    }

    public function photo(Request $request, ShortfallCase $case)
    {
        $this->role($request, $case, ['customer', 'supplier', 'admin']);
        abort_unless($case->photo && Storage::disk('local')->exists($case->photo), 404);

        return Storage::disk('local')->response($case->photo);
    }

    /* ---------- PartyRent support ---------- */

    public function adminIndex(Request $request)
    {
        abort_unless($request->user()->hasPerm('complaints.manage'), 403);
        Shortfalls::escalateDue();

        return view('admin.cases', [
            'cases' => ShortfallCase::with(['rentalRequest', 'customer', 'supplier', 'product'])->orderByRaw("status = 'escalated' desc")->orderByRaw("status = 'resolved'")->orderByDesc('id')->get(),
        ]);
    }
}
