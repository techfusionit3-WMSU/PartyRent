<?php

namespace App\Http\Controllers\Business;

use App\Domain\EventRoles;
use App\Models\EventBooking;
use App\Models\EventBookingPart;
use Illuminate\Http\Request;

/** Home pages of the event company role modules: the Coordinator's desk and the Event Crew's events. */
class WorkController extends BusinessController
{
    /** Coordinator: inquiries to answer, quotations waiting, and the events assigned to them. */
    public function coordinator(Request $request)
    {
        $s = $this->biz($request);
        $u = $request->user();
        $open = EventBooking::where('supplier_id', $s->id)->whereIn('status', EventBooking::OPEN)->with(['customer', 'parts', 'coordinator'])->orderBy('event_date')->get();

        return view('business.coordinator', [
            's' => $s,
            'toQuote' => $open->where('status', 'inquiry')->filter(fn ($b) => ! $b->coordinator_id || $b->coordinator_id === $u->id)->values(),
            'waiting' => $open->where('status', 'quoted')->filter(fn ($b) => $b->coordinator_id === $u->id)->values(),
            'mine' => $open->whereIn('status', ['booked', 'confirmed'])->filter(fn ($b) => $b->coordinator_id === $u->id)->values(),
        ]);
    }

    /** Event Crew: the parts their teams work, today and next. */
    public function crew(Request $request)
    {
        $s = $this->biz($request);
        $parts = EventRoles::crewParts($s, $request->user());

        return view('business.crew', [
            's' => $s,
            'today' => $parts->filter(fn (EventBookingPart $p) => $p->date->isToday())->values(),
            'next' => $parts->filter(fn (EventBookingPart $p) => $p->date->isAfter(today()))->values(),
            'late' => $parts->filter(fn (EventBookingPart $p) => $p->date->isBefore(today()))->values(),
        ]);
    }

    /** Owner: put a coordinator on a booking. */
    public function setCoordinator(Request $request, EventBooking $eventBooking)
    {
        $b = $this->own($request, $eventBooking);
        $v = $request->validate(['coordinator' => 'nullable|integer']);

        return $this->done(EventRoles::setCoordinator($b, $v['coordinator'] ?? null, $request->user()), 'Coordinator updated.');
    }
}
