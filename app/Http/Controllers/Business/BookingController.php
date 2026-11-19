<?php

namespace App\Http\Controllers\Business;

use App\Domain\Events;
use App\Models\EventBooking;
use App\Models\EventPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Inquiries, quotations and event bookings of a business; confirming the customer's payments. */
class BookingController extends BusinessController
{
    public const TABS = ['inquiry' => 'New inquiries', 'quoted' => 'Quoted', 'upcoming' => 'Upcoming events', 'completed' => 'Completed', 'closed' => 'Closed'];

    public function index(Request $request)
    {
        $s = $this->biz($request);
        Events::expire();
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'inquiry';
        $all = EventBooking::where('supplier_id', $s->id)->with(['customer', 'package', 'payments'])->get();
        $groups = [
            'inquiry' => $all->where('status', 'inquiry'),
            'quoted' => $all->where('status', 'quoted'),
            'upcoming' => $all->whereIn('status', ['booked', 'confirmed']),
            'completed' => $all->where('status', 'completed'),
            'closed' => $all->whereIn('status', ['declined', 'cancelled', 'expired']),
        ];
        $list = $groups[$tab]->sortBy(fn ($b) => $b->event_date->toDateString().$b->id);
        if (in_array($tab, ['completed', 'closed'], true)) {
            $list = $list->reverse();
        }

        return view('business.bookings', ['s' => $s, 'tab' => $tab, 'list' => $list->values(), 'counts' => array_map('count', $groups)]);
    }

    public function show(Request $request, EventBooking $eventBooking)
    {
        $b = $this->own($request, $eventBooking)->load(['customer', 'package', 'payments']);
        $s = $b->supplier;

        return view('business.booking', [
            'b' => $b, 's' => $s, 'set' => $s->bizSettings(),
            'heldThatDay' => Events::heldOn($s, $b->event_date->toDateString(), $b->id),
            'others' => EventBooking::where('supplier_id', $s->id)->whereDate('event_date', $b->event_date)->where('id', '!=', $b->id)->whereIn('status', EventBooking::OPEN)->get(),
        ]);
    }

    public function quote(Request $request, EventBooking $eventBooking)
    {
        $b = $this->own($request, $eventBooking);
        $v = $request->validate([
            'items' => 'required|array|min:1|max:30', 'items.*.label' => 'nullable|string|max:120', 'items.*.amount' => 'nullable|numeric|min:0|max:100000000',
            'reservePct' => 'required|numeric|min:0|max:100', 'depositPct' => 'required|numeric|min:0|max:100',
            'valid_days' => 'required|integer|min:1|max:30', 'note' => 'nullable|string|max:1000',
        ]);

        return $this->done(Events::quote($b, $request->user(), $v), 'Quotation sent to '.$b->customer->name.'.');
    }

    public function decline(Request $request, EventBooking $eventBooking)
    {
        $b = $this->own($request, $eventBooking);

        return $this->done(Events::decline($b, $request->user(), trim((string) $request->input('reason'))), 'Inquiry declined. The customer was told.');
    }

    public function cancel(Request $request, EventBooking $eventBooking)
    {
        $b = $this->own($request, $eventBooking);
        $request->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->done(Events::cancel($b, $request->user(), 'business', trim($request->input('reason'))), 'Booking cancelled. The customer was told.');
    }

    public function complete(Request $request, EventBooking $eventBooking)
    {
        return $this->done(Events::complete($this->own($request, $eventBooking), $request->user()), 'Event marked as done.');
    }

    public function payments(Request $request)
    {
        $s = $this->biz($request);
        Events::expire();
        $pay = EventPayment::whereHas('booking', fn ($q) => $q->where('supplier_id', $s->id))->with('booking.customer')->orderBy('due_date')->get();

        return view('business.payments', [
            's' => $s,
            'pending' => $pay->where('status', 'pending')->values(),
            'due' => $pay->where('status', 'due')->filter(fn ($p) => $p->booking && in_array($p->booking->status, ['booked', 'confirmed'], true))->values(),
            'paid' => $pay->where('status', 'paid')->sortByDesc('paid_at')->take(50)->values(),
        ]);
    }

    public function confirmPayment(Request $request, EventPayment $eventPayment)
    {
        $this->own($request, $eventPayment->booking);
        $ok = $request->input('decision') === 'confirm';
        if (! $ok) {
            $request->validate(['reason' => 'required|string|min:3|max:255']);
        }
        $res = Events::confirmPayment($eventPayment, $request->user(), $ok, trim((string) $request->input('reason')));

        return $this->done($res, $ok ? 'Payment confirmed. The customer was told.' : 'Payment sent back to the customer.');
    }

    public function remind(Request $request, EventPayment $eventPayment)
    {
        $this->own($request, $eventPayment->booking);

        return $this->done(Events::remind($eventPayment, $request->user()), 'Reminder sent to the customer.');
    }

    public function proof(Request $request, EventPayment $eventPayment)
    {
        $this->own($request, $eventPayment->booking);
        abort_unless($eventPayment->proof_path && Storage::disk('local')->exists($eventPayment->proof_path), 404);

        return Storage::disk('local')->response($eventPayment->proof_path);
    }
}
