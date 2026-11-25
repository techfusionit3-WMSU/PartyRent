<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Transfers;
use App\Http\Controllers\Controller;
use App\Models\RentalTransfer;
use Illuminate\Http\Request;

/** Stock lent between branches for a combined rental: send, receive, send back / keep, and the printable slip. */
class TransferController extends Controller
{
    private function own(Request $request, RentalTransfer $t): RentalTransfer
    {
        abort_unless($t->supplier_id === $request->user()->shop()?->id, 404);

        return $t;
    }

    public function step(Request $request, RentalTransfer $transfer, string $step)
    {
        $t = $this->own($request, $transfer);
        if (! Transfers::can($request->user(), $t, $step)) {
            return back()->with('error', $step === 'send' ? 'Only the lending branch ('.$t->from?->name.') sends these items.' : 'Only the receiving branch ('.$t->to?->name.') can do this step.');
        }
        $res = Transfers::step($t, $step, $request->user(), (string) $request->input('note', ''));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Transfer updated: '.$t->fresh()->statusLabel().'.' : $res['error']);
    }

    /** Printable transfer slip (released by / received by signatures). */
    public function slip(Request $request, RentalTransfer $transfer)
    {
        $t = $this->own($request, $transfer)->load(['rentalRequest', 'product', 'from', 'to', 'sender', 'receiver', 'closer']);

        return view('supplier.transfer-slip', ['t' => $t, 'sup' => $request->user()->shop()]);
    }
}
