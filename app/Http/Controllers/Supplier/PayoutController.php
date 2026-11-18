<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Finance;
use App\Domain\Notifier;
use App\Domain\Payouts;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Models\RentalRequest;
use Illuminate\Http\Request;

/**
 * A shop's earnings and scheduled payouts. The owner opens it in supplier mode; the shop's cashier opens the
 * same page in the cashier workspace (read-only unless their role may manage the shop).
 */
class PayoutController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $s = $u->shop();
        if (! $s) {
            return redirect()->route($u->homeRoute(session('mode')))->with('error', 'Payouts belong to a shop.');
        }
        Payouts::closeDue(null, $s->id);
        $inCashier = $request->routeIs('cashier.*');
        $schedule = Payouts::scheduleOf($s);

        return view('supplier.payouts', [
            's' => $s,
            'sum' => Payouts::summary($s->id),
            'pct' => Payouts::pct(),
            'schedule' => $schedule,
            'period' => Payouts::period(today_iso(), $schedule),
            'nextDate' => Payouts::nextPayoutDate($s),
            'owed' => Payouts::owed($s),
            'cashPaused' => Payouts::cashPaused($s),
            'canManage' => ! $inCashier && $u->orgCan('shop.manage'),
            'rentalLink' => fn ($r) => $inCashier ? route('cashier.record', ['request' => $r->code]) : route('supplier.requests.show', $r),
            'upcoming' => Payout::where('supplier_id', $s->id)->whereNull('payout_batch_id')->where('status', 'pending')->with('rentalRequest')->orderBy('created_at')->get(),
            'owedRows' => PayoutBatch::where('supplier_id', $s->id)->where('status', 'carried')->whereNull('carried_to_id')->where('net', '<', 0)->orderBy('period_end')->get(),
            'batches' => PayoutBatch::where('supplier_id', $s->id)->with('lines.rentalRequest')->orderByDesc('period_end')->orderByDesc('id')->get(),
            'escrow' => RentalRequest::where('supplier_id', $s->id)->whereIn('status', Finance::ACTIVE)->with(['payments', 'customer'])->orderBy('start_date')->get()
                ->map(fn ($r) => ['r' => $r, 'held' => Payouts::held($r), 'b' => Payouts::breakdown($r)])->filter(fn ($x) => $x['held'] > 0)->values(),
            'awaiting' => Payouts::awaitingRelease($s->id),
        ]);
    }

    public function updateAccount(Request $request)
    {
        $s = $request->user()->shop();
        $data = $request->validate([
            'payout_method' => 'required|in:gcash,bank',
            'payout_bank' => 'required_if:payout_method,bank|nullable|string|max:60',
            'payout_account_name' => 'required|string|max:120',
            'payout_account_no' => ['required', 'string', 'max:40', 'regex:/^[0-9 \-]+$/'],
        ], ['payout_bank.required_if' => 'Enter the bank name.', 'payout_account_no.regex' => 'Use digits only.']);
        if ($data['payout_method'] === 'gcash') {
            $data['payout_bank'] = null;
        }
        $s->update($data);
        Notifier::audit('Updated payout account', $s->name.' · '.$s->payoutAccountLabel());

        return back()->with('success', 'Payout account saved. Future payouts go to '.$s->payoutAccountLabel().'.');
    }

    public function updateSchedule(Request $request)
    {
        $s = $request->user()->shop();
        $data = $request->validate(['payout_schedule' => 'required|in:'.implode(',', array_keys(Payouts::SCHEDULES))], ['payout_schedule.*' => 'Choose how often you want to be paid.']);
        $s->update($data);
        Notifier::audit('Changed payout schedule', $s->name.' · '.Payouts::SCHEDULES[$data['payout_schedule']]);

        return back()->with('success', 'Saved: you are paid '.strtolower(Payouts::SCHEDULES[$data['payout_schedule']]).'. Your next payout is on '.fmt_date(Payouts::nextPayoutDate($s)).'.');
    }
}
