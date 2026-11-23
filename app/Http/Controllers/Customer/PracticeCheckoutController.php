<?php

namespace App\Http\Controllers\Customer;

use App\Domain\PayMongo;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stand-in for PayMongo's hosted checkout page while there is no PayMongo account (PAYMONGO_FAKE=true).
 * Paying sends the customer back to the normal return URL, which settles the payment with the same code
 * a real PayMongo checkout uses.
 */
class PracticeCheckoutController extends Controller
{
    /** @return array{0: array, 1: Payment} */
    private function load(Request $request, string $ref): array
    {
        abort_unless(PayMongo::practice(), 404);
        $s = PayMongo::practiceSession($ref);
        $p = $s ? Payment::where('gateway', 'paymongo')->where('gateway_ref', $ref)->with('rentalRequest')->first() : null;
        abort_unless($p && $p->rentalRequest->customer_id === $request->user()->id, 404);

        return [$s, $p];
    }

    public function show(Request $request, string $ref)
    {
        [$s, $p] = $this->load($request, $ref);
        if ($p->status !== 'awaiting') {
            return redirect()->route('rentals.show', $p->rentalRequest)->with('success', 'This payment was already completed.');
        }

        return view('customer.practice-checkout', ['s' => $s, 'p' => $p, 'r' => $p->rentalRequest, 'ref' => $ref, 'methods' => PayMongo::PRACTICE_METHODS]);
    }

    public function act(Request $request, string $ref)
    {
        [$s, $p] = $this->load($request, $ref);
        $data = $request->validate(['action' => 'required|in:pay,fail,cancel', 'method' => 'nullable|in:'.implode(',', array_keys(PayMongo::PRACTICE_METHODS))]);
        $return = fn (array $q = []) => redirect()->route('rentals.online.return', array_merge([$p->rentalRequest, $p], $q));

        if ($data['action'] === 'cancel') {
            return $return(['cancelled' => 1]);
        }
        if ($data['action'] === 'fail') {
            PayMongo::practiceUpdate($ref, ['status' => 'unpaid', 'failed' => ($s['failed'] ?? 0) + 1]);

            return back()->with('error', 'Payment failed (practice). Nothing was charged. Try again, choose another method, or go back.');
        }
        if ($s['status'] !== 'paid') {
            PayMongo::practiceUpdate($ref, ['status' => 'paid', 'method' => $data['method'] ?? 'gcash', 'paidAmount' => $s['amount'], 'payId' => 'pay_practice_'.Str::lower(Str::random(14))]);
            // the practice gateway notifies PartyRent with a signed webhook, verified before the payment counts
            PayMongo::practiceNotify($ref);
        }

        return $return();
    }
}
