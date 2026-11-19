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
        $list = EventBooking::where('supplier_id', $s->id)->whereIn('status', ['inquiry', 'quoted', 'booked', 'confirmed', 'completed'])
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])->with('customer')->orderBy('start_time')->get()
            ->groupBy(fn ($b) => $b->event_date->toDateString());

        return view('business.calendar', ['s' => $s, 'month' => $month, 'from' => $from, 'to' => $to, 'byDay' => $list, 'max' => (int) $s->bizSettings()['maxEventsPerDay']]);
    }
}
