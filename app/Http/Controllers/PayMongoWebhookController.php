<?php

namespace App\Http\Controllers;

use App\Domain\PayMongo;
use Illuminate\Http\Request;

/** Receives PayMongo events (register https://<your-domain>/webhooks/paymongo for checkout_session.payment.paid). */
class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $body = $request->getContent();
        if (! PayMongo::validSignature($body, (string) $request->header('Paymongo-Signature'))) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        return response()->json(['result' => PayMongo::handleEvent((array) json_decode($body, true))]);
    }
}
