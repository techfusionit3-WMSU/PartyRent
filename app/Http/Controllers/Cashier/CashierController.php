<?php

namespace App\Http\Controllers\Cashier;

use App\Domain\Finance;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RentalRequest;
use Illuminate\Http\Request;

class CashierController extends Controller
{
    public function dashboard(Request $request)
    {
        Rentals::scanOverdue();
        $T = today_iso();
        $active = RentalRequest::whereIn('status', Finance::ACTIVE)->with(['payments', 'customer'])->get();
        $today = Payment::where('status', 'verified')->whereDate('verified_at', $T)->get();

        return view('cashier.dashboard', [
            'pending' => Payment::where('status', 'pending')->with('rentalRequest.customer')->orderBy('submitted_at')->get(),
            'todayIn' => $today->whereNotIn('type', ['refund', 'deposit_refund'])->sum('amount'),
            'todayOut' => $today->whereIn('type', ['refund', 'deposit_refund'])->sum('amount'),
            'todayCount' => $today->count(),
            'overdue' => $active->filter(fn ($r) => $r->fin()['isOverdue']),
            'outstanding' => $active->sum(fn ($r) => $r->fin()['balance']),
            'depositsHeld' => RentalRequest::whereIn('status', array_merge(Finance::ACTIVE, ['completed']))->with('payments')->get()->sum(fn ($r) => $r->fin()['depositHeld']),
            'recent' => Payment::where('status', '!=', 'pending')->with(['rentalRequest.customer', 'verifier'])->orderByDesc('verified_at')->take(8)->get(),
        ]);
    }

    public function verification(Request $request)
    {
        $list = Payment::where('status', 'pending')->with(['rentalRequest.customer', 'rentalRequest.payments'])->orderBy('submitted_at')->get();

        return view('cashier.verification', ['list' => $list]);
    }

    public function verify(Request $request, Payment $payment)
    {
        $res = Rentals::verifyPayment($payment, $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $payment->code.' verified. Receipt '.$res['payment']->receipt_no.' issued.' : $res['error']);
    }

    public function reject(Request $request, Payment $payment)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $res = Rentals::rejectPayment($payment, $data['reason'], $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $payment->code.' rejected. The customer was told why.' : $res['error']);
    }

    public function record(Request $request)
    {
        $r = $request->query('request') ? RentalRequest::where('code', strtoupper(trim($request->query('request'))))->with(['payments', 'customer'])->first() : null;

        return view('cashier.record', [
            'r' => $r, 'code' => $request->query('request', ''),
            'open' => RentalRequest::whereIn('status', array_merge(Finance::ACTIVE, ['completed', 'cancelled']))->with('customer')->orderByDesc('created_at')->take(40)->get(),
        ]);
    }

    public function storeRecord(Request $request)
    {
        $data = $request->validate([
            'request' => 'required|string|exists:rental_requests,code',
            'type' => 'required|in:reservation,down,installment,balance,full,deposit,compensation,refund',
            'method' => 'required|in:cash,bank,debit',
            'amount' => 'required|numeric|min:1',
            'date' => 'required|date|before_or_equal:today',
            'reference' => 'required|string|max:80',
            'notes' => 'nullable|string|max:255',
        ]);
        $r = RentalRequest::where('code', $data['request'])->with('payments')->firstOrFail();
        $res = Rentals::cashierRecord($r, $data, $request->user());
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('cashier.record', ['request' => $r->code])->with('success', ($data['type'] === 'refund' ? 'Refund' : 'Payment').' recorded. Receipt '.$res['payment']->receipt_no.' issued.');
    }

    public function balances(Request $request)
    {
        Rentals::scanOverdue();
        $active = RentalRequest::whereIn('status', Finance::ACTIVE)->with(['payments', 'customer'])->orderBy('start_date')->get();

        return view('cashier.balances', [
            'rows' => $active->filter(fn ($r) => $r->fin()['balance'] > 0 || $r->fin()['isOverdue'])->values(),
            'tab' => $request->query('tab', 'all'),
        ]);
    }

    public function reports(Request $request)
    {
        abort_unless($request->user()->hasPerm('reports.view'), 403);
        $rg = \App\Support\Report::range($request);
        $ver = Payment::where('status', 'verified')->whereBetween('date', [$rg['from'], $rg['to']])->with(['rentalRequest', 'verifier'])->get();
        $rental = $ver->whereIn('type', Finance::RENTAL_TYPES);
        $days = date_range(diff_days($rg['from'], $rg['to']) > 45 ? add_days($rg['to'], -29) : $rg['from'], $rg['to']);

        return view('cashier.reports', [
            'rg' => $rg,
            'collected' => $rental->sum('amount'),
            'refunds' => $ver->where('type', 'refund')->sum('amount'),
            'depositsIn' => $ver->where('type', 'deposit')->sum('amount'),
            'depositsOut' => $ver->where('type', 'deposit_refund')->sum('amount'),
            'compensation' => $ver->where('type', 'compensation')->sum('amount'),
            'rejected' => Payment::where('status', 'rejected')->whereBetween('date', [$rg['from'], $rg['to']])->count(),
            'daily' => array_map(fn ($d) => ['label' => (string) (int) substr($d, 8), 'value' => $rental->where('date', $d)->sum('amount')], $days),
            'byMethod' => collect(Payment::METHOD_LABEL)->map(fn ($l, $k) => ['label' => $l, 'value' => $rental->where('method', $k)->sum('amount')])->values(),
            'byType' => collect(Payment::TYPE_LABEL)->map(fn ($l, $k) => ['label' => $l, 'value' => $ver->where('type', $k)->sum('amount')])->filter(fn ($x) => $x['value'] > 0)->values(),
            'byCashier' => $ver->where('recorded_by', '!=', 'supplier')->groupBy(fn ($p) => $p->verifier->name ?? 'System')->map(fn ($g, $name) => ['label' => $name, 'value' => $g->count()])->sortByDesc('value')->values(),
            'count' => $ver->count(),
        ]);
    }

    public function reportsCsv(Request $request)
    {
        abort_unless($request->user()->hasPerm('reports.view'), 403);
        $rg = \App\Support\Report::range($request);
        $rows = [['Date', 'Transaction', 'Receipt', 'Request', 'Customer', 'Type', 'Method', 'Reference', 'Amount', 'Status', 'Verified by', 'Verified at']];
        Payment::whereBetween('date', [$rg['from'], $rg['to']])->with(['rentalRequest.customer', 'verifier'])->orderBy('date')->orderBy('id')->each(function ($p) use (&$rows) {
            $rows[] = [$p->date, $p->code, $p->receipt_no, $p->rentalRequest->code, $p->rentalRequest->customer->name, $p->typeLabel(), $p->methodLabel(), $p->reference, ($p->isOutflow() ? -1 : 1) * $p->amount, $p->status, $p->verifier->name ?? '', $p->verified_at?->toDateTimeString()];
        });

        return \App\Support\Report::csv('partyrent-payments-'.$rg['from'].'-to-'.$rg['to'].'.csv', $rows);
    }

    public function history(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['pending', 'verified', 'rejected'], true) ? $request->query('status') : null;
        $list = Payment::with(['rentalRequest.customer', 'verifier'])
            ->when($status, fn ($x) => $x->where('status', $status))
            ->when($q, fn ($x) => $x->where(fn ($w) => $w->where('code', 'like', "%$q%")->orWhere('reference', 'like', "%$q%")->orWhere('receipt_no', 'like', "%$q%")->orWhereHas('rentalRequest', fn ($r) => $r->where('code', 'like', "%$q%"))))
            ->orderByDesc('submitted_at')->paginate(25)->withQueryString();

        return view('cashier.history', ['list' => $list, 'q' => $q, 'status' => $status]);
    }
}
