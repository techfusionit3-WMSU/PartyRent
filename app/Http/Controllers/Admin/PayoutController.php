<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payouts;
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
        $tab = in_array($request->query('tab'), ['pending', 'release', 'paid', 'suppliers'], true) ? $request->query('tab') : 'pending';
        $sum = Payouts::summary();
        $suppliers = Supplier::whereIn('id', array_keys($sum))->orderBy('name')->get();

        return view('admin.payouts', [
            'tab' => $tab,
            'pending' => Payout::where('status', 'pending')->with(['supplier', 'rentalRequest'])->orderBy('created_at')->get(),
            'paid' => Payout::where('status', 'paid')->with(['supplier', 'rentalRequest', 'payer'])->orderByDesc('paid_at')->take(100)->get(),
            'awaiting' => Payouts::awaitingRelease(),
            'sum' => $sum, 'suppliers' => $suppliers,
            'totals' => collect(['escrow', 'awaiting', 'pending', 'paid', 'commission'])->mapWithKeys(fn ($k) => [$k => r2(collect($sum)->sum($k))])->all(),
            'pct' => Payouts::pct(),
        ]);
    }

    public function release(Request $request, RentalRequest $rentalRequest)
    {
        $this->need($request);
        $res = Payouts::release($rentalRequest);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['payout']->code.' released: '.money($res['payout']->net).' to '.$rentalRequest->supplier->name.'.' : $res['error']);
    }

    public function pay(Request $request, Payout $payout)
    {
        $this->need($request);
        $data = $request->validate(['method' => 'required|in:gcash,bank', 'reference' => 'required|string|max:80', 'notes' => 'nullable|string|max:255']);
        $res = Payouts::markPaid($payout, $data, $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $payout->code.' marked as paid. The supplier was notified.' : $res['error']);
    }
}
