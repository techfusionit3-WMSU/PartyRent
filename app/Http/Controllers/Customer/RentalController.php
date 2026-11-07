<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Finance;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\Review;
use Illuminate\Http\Request;

class RentalController extends Controller
{
    private function own(Request $request, RentalRequest $r): RentalRequest
    {
        abort_unless($r->customer_id === $request->user()->id, 404);
        $r->load(['payments', 'supplier']);

        return $r;
    }

    public function index(Request $request)
    {
        $all = RentalRequest::where('customer_id', $request->user()->id)->with(['payments', 'supplier'])->latest()->get();
        $groups = [
            'all' => fn ($r) => true, 'pending' => fn ($r) => $r->status === 'pending', 'active' => fn ($r) => $r->isActive(),
            'completed' => fn ($r) => $r->status === 'completed', 'closed' => fn ($r) => in_array($r->status, ['declined', 'cancelled'], true),
        ];
        $tab = array_key_exists($request->query('tab'), $groups) ? $request->query('tab') : 'all';
        $q = mb_strtolower((string) $request->query('q', ''));
        $list = $all->filter($groups[$tab])->filter(fn ($r) => ! $q || str_contains(mb_strtolower($r->code.' '.$r->event_name.' '.$r->supplier->name), $q));

        return view('customer.rentals', ['list' => $list, 'tab' => $tab, 'q' => $request->query('q', ''), 'counts' => array_map(fn ($fn) => $all->filter($fn)->count(), $groups)]);
    }

    public function show(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $f = $r->fin();
        $types = [];
        if ($r->isActive()) {
            $sug = Finance::suggestedNext($r);
            $left = r2($f['balance'] - $f['pendingRental']);
            if ($left > 0.009) {
                $types[] = [$sug['type'], $sug['label'].' (suggested)', $sug['amount'] ?: $left];
                if ($sug['amount'] < $left - 0.009) {
                    $types[] = ['balance', 'Full remaining balance', $left];
                }
            }
            if ($f['depositPaid'] < $f['depositRequired']) {
                $types[] = ['deposit', 'Security deposit (refundable)', r2($f['depositRequired'] - $f['depositPaid'])];
            }
            if ($f['compensationDue'] > 0) {
                $types[] = ['compensation', 'Damage compensation', $f['compensationDue']];
            }
        }

        return view('customer.rental', [
            'r' => $r, 'f' => $f, 'sup' => $r->supplier, 'payTypes' => $types,
            'policy' => Rentals::cancelPolicy($r), 'review' => $r->review,
        ]);
    }

    public function confirmation(Request $request, RentalRequest $rentalRequest)
    {
        return view('customer.confirmation', ['r' => $this->own($request, $rentalRequest)]);
    }

    public function cancel(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $res = Rentals::cancelRequest($r, $data['reason']);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Request cancelled. The supplier has been notified.' : $res['error']);
    }

    /** Customer payment submission — pending until a cashier verifies it. */
    public function pay(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate([
            'type' => 'required|in:reservation,down,installment,balance,full,deposit,compensation',
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:cod,bank,debit',
            'reference' => 'nullable|string|max:80',
            'date' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:255',
            'proof' => 'nullable|image|max:8192',
        ]);
        if (! in_array($data['method'], $r->supplier->methods, true)) {
            return back()->with('error', 'This supplier does not accept that payment method.');
        }
        $bank = in_array($data['method'], ['bank', 'debit'], true);
        if ($bank && ! $request->hasFile('proof')) {
            return back()->withInput()->with('warn', 'Please upload your payment proof for bank/debit payments.');
        }
        if ($request->hasFile('proof')) {
            // Proofs are stored privately and only served to authorized roles.
            $data['proof_path'] = $request->file('proof')->store('proofs', 'local');
            $data['proof_name'] = mb_substr($request->file('proof')->getClientOriginalName(), 0, 120);
        }
        $res = Rentals::submitPayment($r, $data, 'customer');
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return back()->with('success', 'Payment submitted ('.$res['payment']->code.'). Waiting for cashier verification.');
    }

    public function review(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        abort_unless($r->status === 'completed' && ! $r->review, 422);
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'required|string|min:3|max:1000']);
        $first = collect($r->lines)->firstWhere('type', 'item');
        Review::create(['supplier_id' => $r->supplier_id, 'product_id' => $first['ref_id'] ?? null, 'rental_request_id' => $r->id, 'customer_id' => $r->customer_id, 'rating' => $data['rating'], 'comment' => trim($data['comment'])]);
        $r->supplier->update(['rating' => round(Review::where('supplier_id', $r->supplier_id)->avg('rating'), 2)]);

        return back()->with('success', 'Thanks for your review!');
    }

    public function payments(Request $request)
    {
        $reqs = RentalRequest::where('customer_id', $request->user()->id)->with('payments')->get();
        $pays = Payment::whereIn('rental_request_id', $reqs->pluck('id'))->with('rentalRequest')->orderByDesc('submitted_at')->get();
        $f = in_array($request->query('f'), ['pending', 'verified', 'rejected'], true) ? $request->query('f') : 'all';

        return view('customer.payments', [
            'pays' => $f === 'all' ? $pays : $pays->where('status', $f), 'all' => $pays, 'f' => $f,
            'paid' => $pays->where('status', 'verified')->whereIn('type', Finance::RENTAL_TYPES)->sum('amount'),
            'owed' => $reqs->filter->isActive()->sum(fn ($r) => $r->fin()['balance']),
            'activeCount' => $reqs->filter->isActive()->count(),
            'held' => $reqs->sum(fn ($r) => $r->fin()['depositHeld']),
        ]);
    }

    public function profile(Request $request)
    {
        $u = $request->user();
        $reqs = RentalRequest::where('customer_id', $u->id)->get();

        return view('customer.profile', ['u' => $u, 'sup' => $u->supplier, 'reqs' => $reqs, 'welcome' => $request->boolean('welcome')]);
    }
}
