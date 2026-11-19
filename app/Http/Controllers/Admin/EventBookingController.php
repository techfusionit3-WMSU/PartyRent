<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Events;
use App\Http\Controllers\Controller;
use App\Models\EventBooking;
use App\Models\EventPayment;
use Illuminate\Http\Request;

/** Admin view of event companies' bookings, and online event payments PartyRent still has to send to the business. */
class EventBookingController extends Controller
{
    public const TABS = ['open' => 'Open', 'upcoming' => 'Confirmed & upcoming', 'completed' => 'Completed', 'closed' => 'Closed', 'tosend' => 'To send to businesses'];

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPerm('transactions.view'), 403, 'You do not have permission for this page.');
        Events::expire();
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'open';
        $q = trim((string) $request->query('q', ''));
        $all = EventBooking::with(['supplier', 'customer', 'payments'])->latest()->get()
            ->filter(fn ($b) => $q === '' || str_contains(mb_strtolower($b->code.' '.$b->supplier->name.' '.$b->customer->name.' '.$b->event_type), mb_strtolower($q)));
        $groups = [
            'open' => $all->whereIn('status', ['inquiry', 'quoted', 'booked']),
            'upcoming' => $all->where('status', 'confirmed'),
            'completed' => $all->where('status', 'completed'),
            'closed' => $all->whereIn('status', ['declined', 'cancelled', 'expired']),
        ];
        $toSend = EventPayment::where('status', 'paid')->whereNotNull('gateway')->whereNull('remitted_at')->with('booking.supplier')->orderBy('paid_at')->get();
        $paid = EventPayment::where('status', 'paid')->get();

        return view('admin.events', [
            'tab' => $tab, 'q' => $q, 'list' => $tab === 'tosend' ? collect() : $groups[$tab]->sortBy('event_date')->values(),
            'counts' => array_map('count', $groups) + ['tosend' => $toSend->count()], 'toSend' => $toSend,
            'stats' => [
                'bookings' => $all->whereIn('status', ['confirmed', 'completed'])->count(),
                'value' => (float) $all->whereIn('status', ['booked', 'confirmed', 'completed'])->sum(fn ($b) => $b->total()),
                'paid' => (float) $paid->sum('amount'),
                'online' => (float) $toSend->sum('amount'),
            ],
            'canSend' => $request->user()->hasPerm('payouts.manage'),
        ]);
    }

    public function remit(Request $request, EventPayment $eventPayment)
    {
        abort_unless($request->user()->hasPerm('payouts.manage'), 403, 'You do not have permission to send payouts.');
        $res = Events::remit($eventPayment, $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? money($eventPayment->amount).' marked as sent to '.$eventPayment->booking->supplier->name.'.' : $res['error']);
    }
}
