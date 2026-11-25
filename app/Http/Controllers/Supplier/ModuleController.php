<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Finance;
use App\Domain\Jobs;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\RequestMessage;
use App\Models\ShortfallCase;
use Illuminate\Http\Request;

/**
 * Home pages of the company role modules (App\Support\CompanyModules). Each one shows only what that role
 * works on; the pages behind them (requests, inventory, schedule…) are shared and guarded by permissions.
 * The Owner module's home is SupplierController::dashboard; the Cashier module's is CashierController::dashboard.
 */
class ModuleController extends Controller
{
    /** Front Desk: requests to answer, unread customer messages, installment plans to review, open cases. */
    public function desk(Request $request)
    {
        $sup = $request->user()->shop();
        $T = today_iso();
        $reqs = RentalRequest::forShop($sup)->whereIn('status', array_merge(['pending'], Finance::ACTIVE))->with('customer')->get();
        $pend = $reqs->where('status', 'pending')->sortBy('created_at')->values();
        $lastCustomerMsg = RequestMessage::whereIn('rental_request_id', $reqs->pluck('id'))->where('side', 'customer')
            ->selectRaw('rental_request_id, max(created_at) as last_at')->groupBy('rental_request_id')->pluck('last_at', 'rental_request_id');
        $unread = $reqs->filter(fn ($r) => isset($lastCustomerMsg[$r->id]) && (! $r->chat_supplier_seen_at || $r->chat_supplier_seen_at->lt($lastCustomerMsg[$r->id])))->values();
        $plans = $reqs->filter(fn ($r) => ($r->installment_plan['status'] ?? null) === 'proposed')->values();

        return view('supplier.modules.desk', [
            'sup' => $sup, 'T' => $T, 'pend' => $pend, 'unread' => $unread, 'plans' => $plans,
            'conflicts' => $pend->filter(fn ($r) => inv()->check($r->lines, $r->id)['bad'])->pluck('id')->all(),
            'cases' => ShortfallCase::where('supplier_id', $sup->id)->whereHas('rentalRequest', fn ($q) => $q->forShop($sup))->where('status', '!=', 'resolved')->latest()->limit(8)->get(),
            'soon' => $reqs->whereIn('status', Jobs::BEFORE_RELEASE)->filter(fn ($r) => $r->start_date <= add_days($T, 7))->sortBy('start_date')->values(),
        ]);
    }

    /** Inventory Keeper: what to prepare for the next 3 days, returns to inspect, units out of service, low stock. */
    public function stock(Request $request)
    {
        $sup = $request->user()->shop();
        $T = today_iso();
        $prods = Product::where('supplier_id', $sup->id)->whereNotIn('status', ['deleted', 'archived'])->get();
        inv()->primeProducts($prods);
        $reqs = RentalRequest::forShop($sup, true)->whereIn('status', Jobs::BEFORE_RELEASE)
            ->whereBetween('start_date', [$T, add_days($T, 3)])->with('customer')->orderBy('start_date')->get();
        $prep = [];
        foreach ($reqs as $r) {
            $first = inv()->demandMap($r->lines)[$r->start_date] ?? [];
            foreach ($first as $pid => $q) {
                $prep[$pid] ??= ['p' => inv()->product($pid), 'qty' => 0, 'codes' => []];
                $prep[$pid]['qty'] += $q;
                $prep[$pid]['codes'][] = $r->code;
            }
        }
        uasort($prep, fn ($a, $b) => $b['qty'] <=> $a['qty']);
        $out = [];
        foreach ($prods->reject->isSale() as $p) {
            $b = inv()->breakdown($p->id, $T);
            if ($b && ($b['maintenance'] + $b['damaged'] + $b['missing']) > 0) {
                $out[] = ['p' => $p, 'maintenance' => $b['maintenance'], 'damaged' => $b['damaged'], 'missing' => $b['missing']];
            }
        }

        return view('supplier.modules.stock', [
            'sup' => $sup, 'T' => $T, 'prep' => array_values(array_filter($prep, fn ($x) => $x['p'])), 'reqs' => $reqs, 'out' => $out,
            'inspect' => RentalRequest::forShop($sup)->whereIn('status', ['return_pending', 'inspection'])->with('customer')->orderBy('end_date')->get(),
            'low' => $prods->filter->isLowStock()->values(),
        ]);
    }

    /** Field Crew: deliveries, pickups and returns for today and the coming days, with the address to go to. */
    public function crew(Request $request)
    {
        $sup = $request->user()->shop();
        $T = today_iso();
        $jobs = Jobs::list(Jobs::active($sup), 7);

        return view('supplier.modules.crew', [
            'sup' => $sup, 'T' => $T,
            'late' => array_values(array_filter($jobs, fn ($j) => $j['d'] < $T)),
            'today' => array_values(array_filter($jobs, fn ($j) => $j['d'] === $T)),
            'next' => array_values(array_filter($jobs, fn ($j) => $j['d'] > $T)),
        ]);
    }
}
