<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Finance;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Support\Report;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /** Day / week / month inventory calendar for one item. */
    public function calendar(Request $request)
    {
        $sup = $request->user()->supplier;
        $prods = Product::where('supplier_id', $sup->id)->where('status', '!=', 'archived')->orderBy('name')->get();
        if ($prods->isEmpty()) {
            return view('supplier.calendar', ['prods' => $prods]);
        }
        inv()->primeProducts($prods);
        $p = $prods->firstWhere('id', (int) $request->query('item')) ?? $prods->first();
        $view = in_array($request->query('view'), ['day', 'week', 'month'], true) ? $request->query('view') : 'month';
        $cursor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('d')) ? $request->query('d') : today_iso();
        $c = Carbon::parse($cursor);
        if ($view === 'month') {
            $first = $c->copy()->startOfMonth();
            $days = date_range($first->copy()->subDays($first->dayOfWeek)->toDateString(), $first->copy()->subDays($first->dayOfWeek)->addDays(41)->toDateString());
            $prev = $first->copy()->subMonth()->toDateString();
            $next = $first->copy()->addMonth()->toDateString();
            $title = $first->format('F Y');
            $month = $first->month;
        } elseif ($view === 'week') {
            $start = $c->copy()->subDays($c->dayOfWeek);
            $days = date_range($start->toDateString(), $start->copy()->addDays(6)->toDateString());
            $prev = $c->copy()->subWeek()->toDateString();
            $next = $c->copy()->addWeek()->toDateString();
            $title = 'Week of '.fmt_date($start);
            $month = null;
        } else {
            $days = [$cursor];
            $prev = $c->copy()->subDay()->toDateString();
            $next = $c->copy()->addDay()->toDateString();
            $title = fmt_day($cursor);
            $month = null;
        }
        // returns due the day after a rental ends
        $returns = [];
        foreach (RentalRequest::where('supplier_id', $sup->id)->whereIn('status', Finance::ACTIVE)->get() as $r) {
            foreach ($r->lines as $l) {
                if (! empty(inv()->unitsForLine($l, $r->end_date)[$p->id])) {
                    $returns[add_days($r->end_date, 1)] = ($returns[add_days($r->end_date, 1)] ?? 0) + 1;
                }
            }
        }
        $sel = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('sel')) ? $request->query('sel') : ($view === 'day' ? $cursor : today_iso());
        $cells = array_map(fn ($d) => ['d' => $d, 'b' => inv()->breakdown($p->id, $d), 'in' => $month === null || (int) substr($d, 5, 2) === $month, 'ret' => $returns[$d] ?? 0], $days);
        $selB = inv()->breakdown($p->id, $sel);
        $bookings = collect(array_merge(
            array_map(fn ($x) => $x + ['kind' => 'Booked'], $selB['reservedRefs']),
            array_map(fn ($x) => $x + ['kind' => 'Pending'], $selB['pendingRefs'])
        ))->map(fn ($x) => $x + ['r' => RentalRequest::with('customer')->find($x['request_id'])]);

        return view('supplier.calendar', compact('prods', 'p', 'view', 'cursor', 'cells', 'prev', 'next', 'title', 'sel', 'selB', 'bookings'));
    }

    public function damages(Request $request)
    {
        $list = RentalRequest::where('supplier_id', $request->user()->supplier->id)->whereNotNull('inspection')->with(['customer', 'payments'])->get()
            ->sortByDesc(fn ($r) => $r->inspection['at'] ?? '')->values();

        return view('supplier.damages', ['list' => $list]);
    }

    private function data(Request $request): array
    {
        $sup = $request->user()->supplier;
        $rg = Report::range($request);
        $reqs = RentalRequest::where('supplier_id', $sup->id)->with(['customer', 'payments'])->get();
        $ids = $reqs->pluck('id');
        $ver = Payment::whereIn('rental_request_id', $ids)->where('status', 'verified')->get();
        $net = fn ($list) => $list->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $list->where('type', 'refund')->sum('amount');
        $inPeriod = $ver->filter(fn ($p) => $p->date >= $rg['from'] && $p->date <= $rg['to']);
        $inRange = $reqs->filter(fn ($r) => $r->created_at->toDateString() >= $rg['from'] && $r->created_at->toDateString() <= $rg['to']);

        return compact('sup', 'rg', 'reqs', 'ver', 'net', 'inPeriod', 'inRange');
    }

    public function reports(Request $request)
    {
        extract($this->data($request));
        $days = date_range(diff_days($rg['from'], $rg['to']) > 45 ? add_days($rg['to'], -29) : $rg['from'], $rg['to']);
        $months = Report::months(6);
        $prods = Product::where('supplier_id', $sup->id)->where('status', '!=', 'archived')->get();
        inv()->primeProducts($prods);
        $next30 = date_range(today_iso(), add_days(today_iso(), 29));
        $util = $prods->reject->isSale()->map(function ($p) use ($next30) {
            $cap = count($next30) * max(0, $p->total_stock - $p->damaged - $p->missing);
            $used = array_sum(array_map(fn ($d) => inv()->reservedOn($p->id, $d), $next30));

            return ['label' => $p->name, 'value' => $cap ? round($used / $cap * 100, 1) : 0];
        })->sortByDesc('value')->values();
        $units = [];
        foreach ($reqs->whereNotIn('status', ['declined', 'cancelled', 'pending']) as $r) {
            foreach (inv()->demandMap($r->lines) as $m) {
                foreach ($m as $pid => $q) {
                    $units[$pid] = ($units[$pid] ?? 0) + $q;
                }
            }
        }
        arsort($units);

        return view('supplier.reports', [
            'rg' => $rg,
            'income' => $net($inPeriod),
            'split' => Finance::incomeSplit($inPeriod->load('rentalRequest')),
            'inRange' => $inRange,
            'outstanding' => $reqs->filter(fn ($r) => $r->isActive() && $r->fin()['balance'] > 0)->values(),
            'dmg' => $reqs->filter(fn ($r) => ($r->inspection['compensation'] ?? 0) > 0)->values(),
            'upcoming' => $reqs->whereIn('status', ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'])->sortBy('start_date')->take(10),
            'daily' => array_map(fn ($d) => ['label' => (string) (int) substr($d, 8), 'value' => $reqs->filter(fn ($r) => $r->created_at->toDateString() === $d)->count()], $days),
            'monthly' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $reqs->filter(fn ($r) => $r->created_at->format('Y-m') === $m['key'])->count()], $months),
            'incomeByMonth' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $net($ver->filter(fn ($p) => str_starts_with($p->date, $m['key'])))], $months),
            'topUnits' => collect(array_slice($units, 0, 8, true))->map(fn ($v, $pid) => ['label' => inv()->product($pid)?->name, 'value' => $v])->values(),
            'util' => $util,
        ]);
    }

    public function reportsCsv(Request $request)
    {
        extract($this->data($request));
        $rows = [['Request', 'Customer', 'Event', 'Start', 'End', 'Status', 'Rental items', 'Sale items', 'Services', 'Total', 'Verified paid', 'Rental income', 'Sales income', 'Balance', 'Payment status', 'Compensation']];
        foreach ($inRange->sortBy('created_at') as $r) {
            $f = $r->fin();
            $pr = $r->pricing ?? [];
            $s = Finance::split($pr, $f['paidRental'] - $f['refunded']);
            $rows[] = [$r->code, $r->customer->name, $r->event_name, $r->start_date, $r->end_date, RentalRequest::statusLabel($r->status),
                $pr['rentalSubtotal'] ?? $pr['itemsSubtotal'] ?? 0, $pr['salesSubtotal'] ?? 0, $pr['servicesSubtotal'] ?? 0,
                $f['payable'], $f['paidRental'], $s['rental'], $s['sales'], $f['balance'], $f['status'], $f['compensation']];
        }

        return Report::csv('partyrent-'.str($sup->name)->slug().'-'.$rg['from'].'-to-'.$rg['to'].'.csv', $rows);
    }
}
