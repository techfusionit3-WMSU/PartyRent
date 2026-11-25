<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Finance;
use App\Domain\Notifier;
use App\Domain\PayoutAccounts;
use App\Domain\Payouts;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\PayoutAccount;
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
            'accounts' => $s->ensurePayoutAccounts(),
            'cardsEnabled' => PayoutAccounts::cardsEnabled(),
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
        // the older one-account form: saved as a payout account and made the default
        $bank = strtoupper(trim((string) $data['payout_bank']));
        PayoutAccounts::add($s, ['category' => $data['payout_method'] === 'gcash' ? 'ewallet' : 'bank',
            'provider' => $data['payout_method'] === 'gcash' ? 'gcash' : (in_array($bank, ['BPI', 'BDO'], true) ? strtolower($bank) : 'other_bank'),
            'provider_name' => $data['payout_bank'], 'account_name' => $data['payout_account_name'], 'account_number' => $data['payout_account_no'],
            'account_type' => 'savings', 'make_default' => true], $request->user());

        return back()->with('success', 'Payout account saved. Future payouts go to '.$s->fresh()->payoutAccountLabel().'.');
    }

    /** Add a payout account (e-wallet or bank; debit cards only through the card provider's secure form). */
    public function storeAccount(Request $request)
    {
        $s = $request->user()->shop();
        $a = PayoutAccounts::add($s, $request->only(['category', 'provider', 'provider_name', 'account_name', 'account_number', 'account_type', 'branch', 'make_default']), $request->user());

        return redirect(route('supplier.payouts').'#account')->with('success', 'Saved '.$a->label().'.'.($a->is_default ? ' Your payouts go here.' : ''));
    }

    public function defaultAccount(Request $request, PayoutAccount $account)
    {
        $s = $request->user()->shop();
        PayoutAccounts::makeDefault($s, $account, $request->user());

        return redirect(route('supplier.payouts').'#account')->with('success', 'Future payouts go to '.$account->label().'. Payouts already on the way are not changed.');
    }

    public function destroyAccount(Request $request, PayoutAccount $account)
    {
        $res = PayoutAccounts::remove($request->user()->shop(), $account, $request->user());

        return redirect(route('supplier.payouts').'#account')->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Payout account removed.' : $res['error']);
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
