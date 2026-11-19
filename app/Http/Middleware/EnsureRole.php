<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Page guard (port of Auth.guard): role:customer | supplier | cashier | admin | superadmin | staff.
 * Customer ⇄ Supplier (Business) mode is kept in the session; visiting a page of the other mode switches it.
 * A shop's cashier is a customer account whose Business Mode is the cashier workspace of that shop.
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
        if (in_array('cashier', $roles, true) && $u->isShopCashier()) {
            session(['mode' => 'supplier']);

            return $next($request);
        }
        if (in_array('supplier', $roles, true) && ! $u->isStaff()) {
            if ($u->isShopCashier()) {
                session(['mode' => 'supplier']);

                return redirect()->route('cashier.dashboard');
            }
            if ($u->isApprovedSupplier()) {
                session(['mode' => 'supplier']);

                return $this->shopPage($request, $next);
            }

            return redirect()->route('start-selling');
        }

        return redirect()->route($u->homeRoute(session('mode')));
    }

    /**
     * Business staff only open the pages their role allows (owners open everything).
     * Solo item-rental shops and business event companies each have their own workspace.
     */
    private function shopPage(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        $biz = (bool) $request->user()->shop()?->isOrganization();
        if (str_starts_with($name, 'business.') && ! $biz) {
            return redirect()->route('supplier.dashboard');
        }
        if (str_starts_with($name, 'supplier.') && $biz && ! collect(\App\Models\ShopStaff::BUSINESS_KEEPS)->contains(fn ($p) => fnmatch($p, $name))) {
            if ($request->expectsJson()) {
                abort(403, 'Business accounts use the business workspace.');
            }

            return redirect()->route('business.dashboard');
        }
        if (! (str_starts_with($name, 'supplier.') || str_starts_with($name, 'business.')) || $request->user()->canShop($name)) {
            return $next($request);
        }
        $msg = 'Your role ('.(\App\Models\ShopStaff::ORG_ROLES[$request->user()->orgRole()] ?? 'none').') cannot open that page. Ask your Organization Admin.';
        if ($request->expectsJson()) {
            abort(403, $msg);
        }

        return redirect()->route($biz ? 'business.dashboard' : 'supplier.dashboard')->with('error', $msg);
    }
}
