<?php

namespace App\Http\Controllers;

use App\Domain\Settings;
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
        $allowed = $r->customer_id === $u->id
            || $r->supplier->owner_id === $u->id
            || $u->hasPerm('payments.view')
            || $u->hasPerm('transactions.view');
        abort_unless($allowed, 404);
    }

    public function receipt(Request $request, Payment $payment)
    {
        $this->authorizeView($request, $payment);
        abort_unless($payment->receipt_no, 404);
        $r = $payment->rentalRequest()->with(['payments', 'customer', 'supplier'])->first();

        return view('account.receipt', ['p' => $payment, 'r' => $r, 'f' => $r->fin()]);
    }

    /** PartyRent's payment QR code (GCash / Maya / QR Ph), shown in the payment form. */
    public function qr()
    {
        $path = (string) Settings::get('payQrPath');
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function proof(Request $request, Payment $payment)
    {
        $this->authorizeView($request, $payment);
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path);
    }
}
