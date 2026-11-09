<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Finance;
use App\Domain\Notifier;
use App\Http\Controllers\Customer\StartSellingController;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function dashboard(Request $request)
    {
        $sup = $request->user()->supplier;
        $T = today_iso();
        $reqs = RentalRequest::where('supplier_id', $sup->id)->with(['payments', 'customer'])->get();
        $prods = Product::where('supplier_id', $sup->id)->where('status', '!=', 'archived')->get();
        inv()->primeProducts($prods);
        $snap = $prods->reject->isSale()->map(fn ($p) => inv()->breakdown($p->id, $T)); // date-based stock: rental items only
        $fins = $reqs->filter(fn ($r) => $r->isActive() || $r->status === 'completed')->map(fn ($r) => $r->fin());
        $pend = $reqs->where('status', 'pending');
        $ids = $reqs->pluck('id');
        $ver = Payment::whereIn('rental_request_id', $ids)->where('status', 'verified')->get();
        $revBy = fn (string $m) => $ver->filter(fn ($p) => str_starts_with($p->date, $m) && in_array($p->type, Finance::RENTAL_TYPES, true))->sum('amount') - $ver->filter(fn ($p) => str_starts_with($p->date, $m) && $p->type === 'refund')->sum('amount');
        $months = collect(range(5, 0))->map(fn ($i) => Carbon::parse($T)->startOfMonth()->subMonths($i))->map(fn ($d) => ['key' => $d->format('Y-m'), 'label' => $d->format('M')]);
        $units = [];
        foreach ($reqs->whereNotIn('status', ['declined', 'cancelled', 'pending']) as $r) {
            foreach (inv()->demandMap($r->lines) as $m) {
                foreach ($m as $pid => $q) {
                    $units[$pid] = ($units[$pid] ?? 0) + $q;
                }
            }
        }
        arsort($units);
        $pendingPay = Payment::whereIn('rental_request_id', $ids)->where('status', 'pending')->get();

        return view('supplier.dashboard', [
            'sup' => $sup, 'reqs' => $reqs, 'prods' => $prods, 'snap' => $snap, 'fins' => $fins, 'pend' => $pend,
            'conflicted' => $pend->filter(fn ($r) => inv()->check($r->lines, $r->id)['bad']),
            'upcoming' => $reqs->whereIn('status', ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'])->sortBy('start_date')->take(6),
            'monthly' => $months->map(fn ($m) => ['label' => $m['label'], 'value' => $reqs->filter(fn ($r) => $r->created_at->format('Y-m') === $m['key'])->count()]),
            'revenue' => $months->map(fn ($m) => ['label' => $m['label'], 'value' => $revBy($m['key'])]),
            'thisMonth' => $revBy(substr($T, 0, 7)),
            'monthSplit' => Finance::incomeSplit($ver->filter(fn ($p) => str_starts_with($p->date, substr($T, 0, 7)))->load('rentalRequest')),
            'lowStock' => $prods->filter->isLowStock()->values(),
            'topUnits' => collect(array_slice($units, 0, 6, true))->map(fn ($v, $pid) => ['label' => inv()->product($pid)?->name, 'value' => $v])->values(),
            'pendingPay' => $pendingPay,
        ]);
    }

    public function schedule(Request $request)
    {
        $sup = $request->user()->supplier;
        $T = today_iso();
        $reqs = RentalRequest::where('supplier_id', $sup->id)->whereIn('status', Finance::ACTIVE)->with(['customer', 'payments'])->get();
        $events = [];
        foreach ($reqs as $r) {
            if (in_array($r->status, ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'], true)) {
                $events[] = ['d' => $r->start_date, 'kind' => $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup', 'r' => $r];
            }
            if (in_array($r->status, ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing'], true)) {
                $events[] = ['d' => add_days($r->end_date, 1), 'kind' => 'Return', 'r' => $r];
            }
            if ($r->status === 'return_pending') {
                $events[] = ['d' => $T, 'kind' => 'Inspect return', 'r' => $r];
            }
        }
        usort($events, fn ($a, $b) => strcmp($a['d'], $b['d']));

        return view('supplier.schedule', [
            'events' => array_values(array_filter($events, fn ($e) => $e['d'] <= add_days($T, 14))), 'reqs' => $reqs, 'T' => $T,
            'cols' => [['To prepare', ['accepted'], 'b-sky'], ['Preparing', ['in_progress'], 'b-purple'], ['Ready / out for delivery', ['ready_pickup', 'out_delivery'], 'b-teal'], ['With customer', ['ongoing'], 'b-pink'], ['Return pending', ['return_pending'], 'b-gold'], ['Inspection', ['inspection'], 'b-purple']],
        ]);
    }

    public function payments(Request $request)
    {
        $sup = $request->user()->supplier;
        $reqs = RentalRequest::where('supplier_id', $sup->id)->with(['payments', 'customer'])->get();
        $pays = Payment::whereIn('rental_request_id', $reqs->pluck('id'))->with('rentalRequest.customer')->orderByDesc('submitted_at')->get();
        $tab = in_array($request->query('tab'), ['pending', 'verified', 'rejected', 'overdue'], true) ? $request->query('tab') : 'all';

        return view('supplier.payments', [
            'pays' => in_array($tab, ['pending', 'verified', 'rejected'], true) ? $pays->where('status', $tab) : $pays,
            'overdue' => $reqs->filter(fn ($r) => $r->isActive() && $r->fin()['isOverdue']), 'tab' => $tab, 'all' => $pays,
            'income' => $pays->where('status', 'verified')->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $pays->where('status', 'verified')->where('type', 'refund')->sum('amount'),
            'outstanding' => $reqs->filter->isActive()->sum(fn ($r) => $r->fin()['balance']),
            'depositsHeld' => $reqs->sum(fn ($r) => $r->fin()['depositHeld']),
        ]);
    }

    public function shop(Request $request)
    {
        $s = $request->user()->supplier;

        return view('supplier.shop', ['s' => $s, 'd' => $s->toArray(), 'p' => $s->policy ?? [], 'logos' => StartSellingController::LOGOS, 'previewCity' => $s->city_code]);
    }

    public function updateShop(Request $request)
    {
        $s = $request->user()->supplier;
        $v = $request->validate(StartSellingController::rules(), StartSellingController::messages());
        $s->update(StartSellingController::toAttributes($v));
        Notifier::audit('Updated shop profile', $s->name);

        return back()->with('success', 'Shop profile saved. New prices and policies apply to new requests only — submitted requests keep their quoted terms.');
    }
}
