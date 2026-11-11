<?php

namespace App\Http\Controllers;

use App\Domain\Xendit;
use Illuminate\Http\Request;

/** Receives Xendit invoice callbacks (set https://<your-domain>/webhooks/xendit as the "Invoices paid" URL). */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! Xendit::validToken((string) $request->header('x-callback-token'))) {
            return response()->json(['error' => 'invalid token'], 401);
        }

        return response()->json(['result' => Xendit::handleCallback((array) $request->json()->all())]);
    }
}
