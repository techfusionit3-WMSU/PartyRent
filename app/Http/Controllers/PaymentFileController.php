<?php

namespace App\Http\Controllers;

use App\Models\PayAccount;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Receipts and payment proofs, served only to the customer, the supplier, cashiers and admins. */
class PaymentFileController extends Controller
{
    private function authorizeView(Request $request, Payment $payment): void
    {
        $u = $request->user();
        $r = $payment->rentalRequest;
        // A shop's own cashier holds payments.view, but only for that shop; platform staff see every shop.
        $shopStaff = $u->staffOf !== null;
        $allowed = $r->customer_id === $u->id
            || $r->supplier->owner_id === $u->id
            || $u->shop()?->id === $r->supplier_id
            || (! $shopStaff && ($u->hasPerm('payments.view') || $u->hasPerm('transactions.view')));
        abort_unless($allowed, 404);
    }

    public function receipt(Request $request, Payment $payment)
    {
        $this->authorizeView($request, $payment);
        abort_unless($payment->receipt_no, 404);
        $r = $payment->rentalRequest()->with(['payments', 'customer', 'supplier'])->first();

        return view('account.receipt', ['p' => $payment, 'r' => $r, 'f' => $r->fin()]);
    }

    /** A PartyRent collection account's QR code (GCash / Maya / QR Ph), shown in the payment form. */
    public function qr(PayAccount $payAccount)
    {
        abort_unless($payAccount->qr_path && Storage::disk('local')->exists($payAccount->qr_path), 404);

        return Storage::disk('local')->response($payAccount->qr_path);
    }

    public function proof(Request $request, Payment $payment)
    {
        $this->authorizeView($request, $payment);
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path);
    }
}
