<?php

namespace App\Http\Controllers\Business;

use App\Domain\Events;
use App\Models\EventBooking;
use App\Models\EventPayment;
use Illuminate\Http\Request;

/** Business dashboard: inquiries to answer, quotations waiting, upcoming events, payments to confirm, revenue. */
class DashboardController extends BusinessController
{
    public function index(Request $request)
    {
        $s = $this->biz($request);
        Events::expire();
        $all = EventBooking::where('supplier_id', $s->id)->with(['customer', 'package', 'payments'])->get();
        $pay = EventPayment::whereIn('event_booking_id', $all->pluck('id'))->get();

        return view('business.dashboard', [
            's' => $s,
            'inquiries' => $all->where('status', 'inquiry')->sortBy('event_date')->values(),
            'quoted' => $all->where('status', 'quoted')->count(),
            'upcoming' => $all->whereIn('status', ['booked', 'confirmed'])->filter(fn ($b) => $b->event_date->gte(today()))->sortBy('event_date')->values(),
            'toConfirm' => $pay->where('status', 'pending')->count(),
            'overdue' => $pay->filter(fn ($p) => $p->isOverdue())->count(),
            'revenue' => (float) $pay->where('status', 'paid')->filter(fn ($p) => $p->paid_at && $p->paid_at->isSameMonth(now()))->sum('amount'),
            'hasPackages' => $s->eventPackages()->exists(),
            'needsSettings' => empty($s->profile('settings.serviceAreas')),
        ]);
    }

    /** Month calendar of events (inquiries, quoted, booked, confirmed, done) with how full each day is. */
    public function calendar(Request $request)
    {
        $s = $this->biz($request);
        Events::expire();
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('m')) ? \Carbon\Carbon::parse($request->query('m').'-01') : today()->startOfMonth();
        $from = $month->copy()->startOfMonth()->startOfWeek(\Carbon\Carbon::SUNDAY);
        $to = $month->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SATURDAY);
        // every part of an event shows on its own date (a wedding's prenup shoot and reception, a fiesta's days)
        $range = [$from->toDateString(), $to->toDateString()];
        $list = collect();
        EventBooking::where('supplier_id', $s->id)->whereIn('status', ['inquiry', 'quoted', 'booked', 'confirmed', 'completed'])
            ->whereHas('parts', fn ($q) => $q->whereBetween('date', $range))->with(['customer', 'parts'])->get()
            ->each(function ($b) use ($list, $range) {
                foreach ($b->parts as $pt) {
                    $d = $pt->date->toDateString();
                    if ($d >= $range[0] && $d <= $range[1]) {
                        $e = clone $b;
                        $e->calPart = $b->parts->count() > 1 ? $pt->name : null;
                        $e->calTime = $pt->start_time;
                        $list->push([$d, $e]);
                    }
                }
            });
        $list = $list->sortBy(fn ($x) => $x[1]->calTime ?? '99')->groupBy(fn ($x) => $x[0])->map(fn ($g) => $g->map(fn ($x) => $x[1])->unique('id')->values());

        return view('business.calendar', ['s' => $s, 'month' => $month, 'from' => $from, 'to' => $to, 'byDay' => $list, 'max' => (int) $s->bizSettings()['maxEventsPerDay']]);
    }
}
