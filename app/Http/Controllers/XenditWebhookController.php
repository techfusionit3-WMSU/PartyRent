<?php

namespace App\Http\Controllers;

use App\Domain\Xendit;
use Illuminate\Http\Request;

/** Receives Xendit callbacks: invoices ("Invoice paid"), payouts ("Payout") and refunds ("Refund"). Set all three to https://<your-domain>/webhooks/xendit. */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! Xendit::validToken((string) $request->header('x-callback-token'))) {
            return response()->json(['error' => 'invalid token'], 401);
        }

        $body = (array) $request->json()->all();
        $event = (string) ($body['event'] ?? '');
        $result = match (true) {
            str_starts_with($event, 'payout.') => \App\Domain\XenditPayouts::handleCallback($body),
            str_starts_with($event, 'refund.') => \App\Domain\Refunds::handleCallback($body),
            default => Xendit::handleCallback($body),
        };

        return response()->json(['result' => $result]);
    }
}
