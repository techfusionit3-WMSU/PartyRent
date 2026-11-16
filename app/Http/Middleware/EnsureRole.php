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
            return $active === 'supplier' ? $this->shopPage($request, $next) : $next($request);
        }

        if (in_array('customer', $roles, true) && ! $u->isStaff()) {
            session(['mode' => 'customer']);

            return $next($request);
        }
        if (in_array('supplier', $roles, true) && ! $u->isStaff()) {
            if ($u->isApprovedSupplier()) {
                session(['mode' => 'supplier']);

                return $this->shopPage($request, $next);
            }

            return redirect()->route('start-selling');
        }

        return redirect()->route($u->homeRoute(session('mode')));
    }

    /** Business staff only open the pages their role allows (owners open everything). */
    private function shopPage(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        if (! str_starts_with($name, 'supplier.') || $request->user()->canShop($name)) {
            return $next($request);
        }
        $msg = 'Your role ('.(\App\Models\ShopStaff::ORG_ROLES[$request->user()->orgRole()] ?? 'none').') cannot open that page. Ask your Organization Admin.';
        if ($request->expectsJson()) {
            abort(403, $msg);
        }

        return redirect()->route('supplier.dashboard')->with('error', $msg);
    }
}
