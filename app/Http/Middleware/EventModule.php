<?php

namespace App\Http\Middleware;

use App\Models\Supplier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the event-company pages (whole-event quotations, event bookings, event types, business workspace)
 * while config partyrent.event_companies is off: PartyRent rents decorations and party items, it does not
 * book events. Payment webhooks are not routed through here, so nothing in flight is lost.
 */
class EventModule
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Supplier::eventCompanies()) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            abort(404);
        }

        return redirect()->route('home')->with('warn', 'PartyRent rents decorations and party items for your event; booking a whole event isn\'t offered. Browse items and packages instead.');
    }
}
