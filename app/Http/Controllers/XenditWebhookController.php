<?php

namespace App\Http\Controllers;

use App\Domain\Xendit;
use Illuminate\Http\Request;

/** Receives Xendit callbacks: invoices ("Invoice paid") and payouts ("Payout"). Set both to https://<your-domain>/webhooks/xendit. */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! Xendit::validToken((string) $request->header('x-callback-token'))) {
            return response()->json(['error' => 'invalid token'], 401);
        }

        $body = (array) $request->json()->all();
        $result = str_starts_with((string) ($body['event'] ?? ''), 'payout.')
            ? \App\Domain\XenditPayouts::handleCallback($body)
            : Xendit::handleCallback($body);

        return response()->json(['result' => $result]);
    }
}
