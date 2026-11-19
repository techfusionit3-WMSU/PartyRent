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
        // a shop member with the Cashier module (owner or Cashier role) opening a cashier page enters that module
        if (in_array('cashier', $roles, true) && ! $u->isStaff() && $u->hasShopCashierModule()) {
            session(['mode' => 'supplier', 'module' => 'cashier']);

            return $next($request);
        }
        if (in_array('supplier', $roles, true) && ! $u->isStaff()) {
            if ($u->isShopCashier()) {
                // only a cashier module: stay there; with other modules, move to the one that owns this page
                $to = \App\Support\CompanyModules::moduleFor($u, (string) $request->route()?->getName());
                if (! $to || $to === 'cashier') {
                    session(['mode' => 'supplier']);

                    return redirect()->route('cashier.dashboard');
                }
                session(['mode' => 'supplier', 'module' => $to]);

                return $this->shopPage($request, $next);
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
        $u = $request->user();
        // the owner's dashboard is the Owner module; a member lands on the home page of their own module
        if ($name === 'supplier.dashboard' && ! $biz && ! in_array('manage', $u->modules(), true) && ! $request->expectsJson()) {
            return redirect()->route($u->homeRoute('supplier'));
        }
        if (! (str_starts_with($name, 'supplier.') || str_starts_with($name, 'business.')) || $u->canShop($name)) {
            // keep the menu in step: a page of another of the user's modules switches to that module
            if (! $biz && ($to = \App\Support\CompanyModules::moduleFor($u, $name)) && $to !== session('module')) {
                session(['module' => $to]);
            }

            return $next($request);
        }
        $msg = 'Your role ('.\App\Models\ShopStaff::rolesLabel($u->orgRoles() ?: ['none']).') cannot open that page. Ask the shop owner.';
        if ($request->expectsJson()) {
            abort(403, $msg);
        }
        $home = $biz ? 'business.dashboard' : $u->homeRoute('supplier');

        return $home === $name ? abort(403, $msg) : redirect()->route($home)->with('error', $msg);
    }
}
