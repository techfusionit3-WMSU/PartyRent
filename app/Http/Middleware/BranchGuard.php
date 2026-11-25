<?php

namespace App\Http\Middleware;

use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\RentalTransfer;
use App\Models\ShortfallCase;
use App\Support\BranchScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branch-level staff never reach another branch's records (App\Support\BranchScope).
 *
 * On shop pages (supplier.*, cashier.* for a shop's cashier) a rental, payment, case, branch or transfer in the URL
 * must belong to one of the user's branches, else 404 (as if it did not exist). A branch that only lends stock to a
 * rental may open that rental read-only and do its transfer steps; everything else needs the rental's home branch.
 * List pages filter with RentalRequest::forShop().
 */
class BranchGuard
{
    /** Rental pages a lending branch may open (read-only view of the rental it lends to). */
    private const LENDER_ROUTES = ['supplier.requests.show', 'supplier.transfers.step', 'supplier.transfers.slip'];

    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        $route = $request->route();
        $name = (string) $route?->getName();
        if (! $u || ! $route || ! (str_starts_with($name, 'supplier.') || str_starts_with($name, 'cashier.'))) {
            return $next($request);
        }
        $ids = BranchScope::allowed($u);
        if ($ids === null) {
            return $next($request);
        }
        if (BranchScope::orgOnly($name)) {
            $msg = 'This page is for the whole organization. Ask the owner or an Organization Admin.';
            if ($request->expectsJson() || ! $request->isMethod('GET')) {
                abort(403, $msg);
            }

            return redirect()->route($u->homeRoute('supplier'))->with('error', $msg);
        }
        foreach ($route->parameters() as $p) {
            $rental = match (true) {
                $p instanceof RentalRequest => $p,
                $p instanceof Payment, $p instanceof ShortfallCase => $p->rentalRequest,
                default => null,
            };
            if ($rental) {
                $home = in_array((int) $rental->branch_id, $ids, true);
                $lends = ! $home && in_array($name, self::LENDER_ROUTES, true)
                    && RentalTransfer::where('rental_request_id', $rental->id)->whereIn('from_location_id', $ids)->where('status', '!=', 'cancelled')->exists();
                abort_unless($home || $lends, 404);
            }
            if ($p instanceof InventoryLocation) {
                abort_unless(in_array($p->id, $ids, true), 404);
            }
            if ($p instanceof RentalTransfer) {
                abort_unless(in_array($p->from_location_id, $ids, true) || in_array($p->to_location_id, $ids, true), 404);
            }
        }

        return $next($request);
    }
}
