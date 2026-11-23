<?php

namespace App\Http\Controllers;

use App\Domain\PaymentChecks;
use App\Domain\Xendit;
use Illuminate\Http\Request;

/** Receives Xendit callbacks: invoices ("Invoice paid"), payouts ("Payout") and refunds ("Refund"). Set all three to https://<your-domain>/webhooks/xendit. */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $body = (array) $request->json()->all();
        // the callback token is checked before anything changes; a refused call is recorded for the admins
        if (! Xendit::validToken((string) $request->header('x-callback-token'))) {
            PaymentChecks::via('webhook', fn () => PaymentChecks::log([
                'gateway' => 'xendit', 'result' => 'rejected', 'verified' => false,
                'gateway_ref' => mb_substr((string) ($body['id'] ?? ''), 0, 80) ?: null,
                'message' => 'Callback refused: the x-callback-token did not match.',
            ]));

            return response()->json(['error' => 'invalid token'], 401);
        }

        $event = (string) ($body['event'] ?? '');
        $result = PaymentChecks::via('webhook', fn () => match (true) {
            str_starts_with($event, 'payout.') => \App\Domain\XenditPayouts::handleCallback($body),
            str_starts_with($event, 'refund.') => \App\Domain\Refunds::handleCallback($body),
            // Xendit sends an invoice callback per status change: the invoice id + status is the event
            default => PaymentChecks::once('xendit:'.($body['id'] ?? '').':'.($body['status'] ?? ''), fn () => Xendit::handleCallback($body)),
        });

        return response()->json(['result' => $result]);
    }
}
