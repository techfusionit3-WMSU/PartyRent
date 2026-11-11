<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payouts;
use App\Domain\XenditPayouts;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\RentalRequest;
use App\Models\Supplier;
use Illuminate\Http\Request;

/** Owner/admin side of the escrow: release held money and send payouts to suppliers. */
class PayoutController extends Controller
{
    private function need(Request $r): void
    {
        abort_unless($r->user()->hasPerm('payouts.manage'), 403, 'You do not have permission for this page.');
    }

    public function index(Request $request)
    {
        $this->need($request);
        // payouts Xendit is still processing: ask for news (localhost gets no webhooks)
        if (XenditPayouts::enabled()) {
            Payout::where('status', 'sending')->take(10)->get()->each(fn ($p) => XenditPayouts::sync($p));
        }
        $tab = in_array($request->query('tab'), ['pending', 'release', 'paid', 'suppliers'], true) ? $request->query('tab') : 'pending';
        $sum = Payouts::summary();
        $suppliers = Supplier::whereIn('id', array_keys($sum))->orderBy('name')->get();

        return view('admin.payouts', [
            'tab' => $tab,
            'pending' => Payout::whereIn('status', ['pending', 'sending'])->with(['supplier', 'rentalRequest'])->orderBy('created_at')->get(),
            'paid' => Payout::where('status', 'paid')->with(['supplier', 'rentalRequest', 'payer'])->orderByDesc('paid_at')->take(100)->get(),
            'awaiting' => Payouts::awaitingRelease(),
            'sum' => $sum, 'suppliers' => $suppliers,
            'totals' => collect(['escrow', 'awaiting', 'pending', 'paid', 'commission'])->mapWithKeys(fn ($k) => [$k => r2(collect($sum)->sum($k))])->all(),
            'pct' => Payouts::pct(), 'xendit' => XenditPayouts::enabled(),
        ]);
    }

    public function release(Request $request, RentalRequest $rentalRequest)
    {
        $this->need($request);
        $res = Payouts::release($rentalRequest);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['payout']->code.' released: '.money($res['payout']->net).' to '.$rentalRequest->supplier->name.'.' : $res['error']);
    }

    /** Send a payout through Xendit (Money-out). */
    public function send(Request $request, Payout $payout)
    {
        $this->need($request);
        $res = XenditPayouts::send($payout, $request->user());
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }

        return back()->with($res['state'] === 'failed' ? 'error' : 'success', match ($res['state']) {
            'paid' => $payout->code.': '.money($payout->net).' sent to '.$payout->supplier->name.' through Xendit. The supplier was notified.',
            'failed' => $payout->code.' failed in Xendit: '.$payout->fresh()->failure,
            default => $payout->code.' is on its way: Xendit is sending '.money($payout->net).' to '.$payout->supplier->name.'. It shows as Paid once it arrives.',
        });
    }

    public function check(Request $request, Payout $payout)
    {
        $this->need($request);
        $state = XenditPayouts::sync($payout);

        return back()->with($state === 'failed' ? 'error' : 'success', match ($state) {
            'paid' => $payout->code.' arrived. The supplier was notified.',
            'failed' => $payout->code.' failed: '.$payout->fresh()->failure,
            'error' => 'Could not reach Xendit just now. Try again in a moment.',
            default => $payout->code.' is still being processed by Xendit.',
        });
    }

    public function pay(Request $request, Payout $payout)
    {
        $this->need($request);
        $data = $request->validate(['method' => 'required|in:gcash,bank', 'reference' => 'required|string|max:80', 'notes' => 'nullable|string|max:255']);
        $res = Payouts::markPaid($payout, $data, $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $payout->code.' marked as paid. The supplier was notified.' : $res['error']);
    }
}
