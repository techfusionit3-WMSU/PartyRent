<?php

namespace App\Http\Controllers;

use App\Domain\PayMongo;
use Illuminate\Http\Request;

/** Receives PayMongo events (register https://<your-domain>/webhooks/paymongo for checkout_session.payment.paid). */
class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        // the signature is checked before anything changes; refused and accepted calls are both recorded
        $result = PayMongo::receiveWebhook($request->getContent(), (string) $request->header('Paymongo-Signature'));

        return $result === null ? response()->json(['error' => 'invalid signature'], 401) : response()->json(['result' => $result]);
    }
}
