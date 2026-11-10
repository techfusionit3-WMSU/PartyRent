<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Finance;
use App\Domain\Notifier;
use App\Domain\Payouts;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\RentalRequest;
use Illuminate\Http\Request;

/** Supplier's earnings: money PartyRent holds for their rentals, and the payouts they received. */
class PayoutController extends Controller
{
    public function index(Request $request)
    {
        $s = $request->user()->supplier;

        return view('supplier.payouts', [
            's' => $s,
            'sum' => Payouts::summary($s->id),
            'pct' => Payouts::pct(),
            'escrow' => RentalRequest::where('supplier_id', $s->id)->whereIn('status', Finance::ACTIVE)->with(['payments', 'customer'])->orderBy('start_date')->get()
                ->map(fn ($r) => ['r' => $r, 'held' => Payouts::held($r), 'b' => Payouts::breakdown($r)])->filter(fn ($x) => $x['held'] > 0)->values(),
            'awaiting' => Payouts::awaitingRelease($s->id),
            'payouts' => Payout::where('supplier_id', $s->id)->with('rentalRequest')->orderByDesc('created_at')->get(),
        ]);
    }

    public function updateAccount(Request $request)
    {
        $s = $request->user()->supplier;
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
}
