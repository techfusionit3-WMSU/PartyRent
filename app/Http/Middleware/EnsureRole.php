<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Page guard (port of Auth.guard): role:customer | supplier | cashier | admin | superadmin | staff.
 * Customer ⇄ Supplier mode is kept in the session; visiting a page of the other mode switches it.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $u = $request->user();
        if (! $u) {
            return redirect()->guest(route('login'));
        }
        if (! $u->isActive()) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('error', 'This account is '.$u->status.'. Please contact PartyRent support.');
        }

        $active = $u->activeRole(session('mode'));
        if (in_array($active, $roles, true) || (in_array('staff', $roles, true) && $u->isStaff())) {
            return $next($request);
        }

        if (in_array('customer', $roles, true) && ! $u->isStaff()) {
            session(['mode' => 'customer']);

            return $next($request);
        }
        if (in_array('supplier', $roles, true) && ! $u->isStaff()) {
            if ($u->isApprovedSupplier()) {
                session(['mode' => 'supplier']);

                return $next($request);
            }

            return redirect()->route('start-selling');
        }

        return redirect()->route($u->homeRoute(session('mode')));
    }
}
