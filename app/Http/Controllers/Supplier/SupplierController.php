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
    /**
     * Minimal dashboard: what needs doing now, four key numbers, and what's coming up.
     * Charts and detailed counts live on Reports / Inventory.
     */
    public function dashboard(Request $request)
    {
        $sup = $request->user()->supplier;
        $T = today_iso();
        $reqs = RentalRequest::where('supplier_id', $sup->id)->with(['payments', 'customer'])->get();
        $prods = Product::where('supplier_id', $sup->id)->where('status', '!=', 'archived')->get();
        inv()->primeProducts($prods);
        $snap = $prods->reject->isSale()->map(fn ($p) => inv()->breakdown($p->id, $T));
        $ids = $reqs->pluck('id');
        $ver = Payment::whereIn('rental_request_id', $ids)->where('status', 'verified')->get();

        $pend = $reqs->where('status', 'pending');
        $toReview = $pend->filter(fn ($r) => $r->fin()['upfrontPaid'] !== false); // null = nothing to pay upfront
        $booked = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'];
        $out = $reqs->filter(fn ($r) => in_array($r->status, $booked, true) && $r->start_date <= $T);
        $returns = $reqs->filter(fn ($r) => ($r->status === 'ongoing' && $r->end_date < $T) || $r->status === 'return_pending');
        $overdue = $reqs->filter(fn ($r) => $r->isActive() && $r->fin()['isOverdue']);
        $conflicted = $pend->filter(fn ($r) => inv()->check($r->lines, $r->id)['bad']);
        $lowStock = $prods->filter->isLowStock()->values();
        $link = fn ($r) => '<a href="'.e(route('supplier.requests.show', $r)).'">'.e($r->code).'</a>';
        $list = fn ($c) => $c->take(4)->map($link)->implode(', ').($c->count() > 4 ? ' +'.($c->count() - 4).' more' : '');

        $todo = array_values(array_filter([
            $toReview->count() ? ['icon' => 'clock', 'tone' => 'gold', 'title' => $toReview->count().' request'.($toReview->count() > 1 ? 's' : '').' ready to accept', 'body' => 'Down payment received. '.$list($toReview), 'href' => route('supplier.requests'), 'cta' => 'Review'] : null,
            $conflicted->count() ? ['icon' => 'alert', 'tone' => 'red', 'title' => 'Not enough stock for '.$conflicted->count().' request'.($conflicted->count() > 1 ? 's' : ''), 'body' => $list($conflicted), 'href' => route('supplier.requests'), 'cta' => 'Check'] : null,
            $out->count() ? ['icon' => 'truck', 'tone' => 'teal', 'title' => $out->count().' rental'.($out->count() > 1 ? 's' : '').' to deliver or hand over', 'body' => $list($out), 'href' => route('supplier.schedule'), 'cta' => 'Open schedule'] : null,
            $returns->count() ? ['icon' => 'box', 'tone' => 'purple', 'title' => $returns->count().' return'.($returns->count() > 1 ? 's' : '').' to receive and check', 'body' => $list($returns), 'href' => route('supplier.schedule'), 'cta' => 'Open schedule'] : null,
            $overdue->count() ? ['icon' => 'card', 'tone' => 'red', 'title' => $overdue->count().' customer'.($overdue->count() > 1 ? 's are' : ' is').' late paying', 'body' => $list($overdue), 'href' => route('supplier.payments', ['tab' => 'overdue']), 'cta' => 'See payments'] : null,
            $lowStock->count() ? ['icon' => 'alert', 'tone' => 'gold', 'title' => 'Running low: '.$lowStock->pluck('name')->take(3)->implode(', '), 'body' => 'Restock these sale items.', 'href' => route('supplier.inventory'), 'cta' => 'Inventory'] : null,
        ]));

        $month = substr($T, 0, 7);
        $split = Finance::incomeSplit($ver->filter(fn ($p) => str_starts_with($p->date, $month))->load('rentalRequest'));

        return view('supplier.dashboard', [
            'sup' => $sup, 'todo' => $todo,
            'waiting' => $pend->count() - $toReview->count(),
            'toReview' => $toReview->count(),
            'active' => $reqs->filter->isActive()->count(),
            'earned' => $split['rental'] + $split['sales'],
            'available' => $snap->sum('available'),
            'items' => $prods->where('status', 'published')->count(),
            'upcoming' => $reqs->filter(fn ($r) => in_array($r->status, $booked, true) && $r->start_date >= $T && $r->start_date <= add_days($T, 14))->sortBy('start_date')->take(6),
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
