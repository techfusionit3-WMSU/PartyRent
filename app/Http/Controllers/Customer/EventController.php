<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Events;
use App\Http\Controllers\Controller;
use App\Models\EventBooking;
use App\Models\EventPackage;
use App\Models\EventPayment;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** The customer's side of booking a business: inquiry, quotation, payment schedule. */
class EventController extends Controller
{
    private function mine(Request $request, EventBooking $b): EventBooking
    {
        abort_unless($b->customer_id === $request->user()->id, 404);

        return $b;
    }

    public function index(Request $request)
    {
        Events::expire();
        $list = EventBooking::where('customer_id', $request->user()->id)->with(['supplier', 'package', 'payments'])->latest()->get();

        return view('customer.events', ['list' => $list]);
    }

    public function show(Request $request, EventBooking $eventBooking)
    {
        Events::expire();
        $b = $this->mine($request, $eventBooking)->fresh(['supplier', 'package', 'payments']);

        return view('customer.event', ['b' => $b, 's' => $b->supplier]);
    }

    public function create(Request $request, Supplier $supplier)
    {
        abort_unless($supplier->isOrganization() && $supplier->status === 'approved', 404);
        // only kinds of events PartyRent verified for this business, and packages for them
        $types = \App\Domain\EventTypes::verified($supplier);
        $packages = $supplier->eventPackages()->where('active', true)->get()->filter(fn ($k) => \App\Domain\EventTypes::offerable($k, $types))->values();
        $pkg = $request->query('package') ? $packages->firstWhere('id', (int) $request->query('package')) : null;

        // the parts each package / event type asks a date for (the form switches them when the choice changes)
        $defs = ['pkg' => $packages->mapWithKeys(fn ($k) => [$k->id => \App\Domain\EventParts::definitions($k, $k->events[0] ?? null)])->all(),
            'type' => $types->keys()->mapWithKeys(fn ($n) => [$n => \App\Domain\EventParts::definitions(null, $n)])->all()];

        return view('customer.event-form', ['s' => $supplier, 'pkg' => $pkg, 'set' => $supplier->bizSettings(), 'packages' => $packages, 'types' => $types, 'defs' => $defs]);
    }

    public function store(Request $request, Supplier $supplier)
    {
        abort_unless($supplier->isOrganization(), 404);
        $v = $request->validate([
            'event_package_id' => 'nullable|integer', 'event_type' => 'required|string|max:60',
            // the parts of the event (each with a date), or one event date for a single-day event
            'parts' => 'nullable|array|max:'.\App\Domain\EventParts::MAX, 'parts.*.date' => 'nullable|date|after:today', 'parts.*.start' => 'nullable|string|max:5', 'parts.*.end' => 'nullable|string|max:5',
            'parts.*.venue' => 'nullable|string|max:255', 'parts.*.include' => 'nullable|boolean',
            'event_date' => 'required_without:parts|nullable|date|after:today', 'start_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'guests' => 'required|integer|min:1|max:100000', 'venue' => 'required|string|min:5|max:255', 'city' => 'nullable|string|max:120',
            'budget' => 'nullable|numeric|min:0|max:100000000', 'notes' => 'nullable|string|max:2000',
        ], ['venue.required' => 'Enter where the event will be held.', 'parts.*.date.after' => 'Every part must be on a future date.', 'event_date.required_without' => 'Choose the event date.']);
        $pkg = ! empty($v['event_package_id']) ? EventPackage::where('supplier_id', $supplier->id)->where('active', true)->find($v['event_package_id']) : null;
        $res = Events::inquire($request->user(), $supplier, $v, $pkg);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('events.show', $res['booking'])->with('success', 'Inquiry sent to '.$supplier->name.'. They will reply with a quotation.');
    }

    public function accept(Request $request, EventBooking $eventBooking)
    {
        $res = Events::accept($this->mine($request, $eventBooking), $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Quotation accepted! Pay the reservation fee to hold your date.' : $res['error']);
    }

    public function cancel(Request $request, EventBooking $eventBooking)
    {
        $b = $this->mine($request, $eventBooking);
        $res = Events::cancel($b, $request->user(), 'customer', trim((string) $request->input('reason')));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Booking cancelled.' : $res['error']);
    }

    public function pay(Request $request, EventBooking $eventBooking, EventPayment $eventPayment)
    {
        $b = $this->mine($request, $eventBooking);
        abort_unless($eventPayment->event_booking_id === $b->id, 404);
        $v = $request->validate([
            'method' => 'required|in:bank,gcash', 'reference' => 'required|string|min:4|max:80',
            'proof' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:8192',
        ], ['proof.required' => 'Attach a screenshot or receipt of your transfer.']);
        $path = $request->file('proof')->store('event-payments/'.$b->id, 'local');
        $res = Events::submitPayment($eventPayment, $request->user(), $v, $path);
        if (! $res['ok']) {
            Storage::disk('local')->delete($path);
        }

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Payment sent. '.$b->supplier->name.' will confirm it once it reaches their account.' : $res['error']);
    }

    private function step(Request $request, EventBooking $b, EventPayment $p): EventPayment
    {
        $this->mine($request, $b);
        abort_unless($p->event_booking_id === $b->id, 404);

        return $p;
    }

    /** "Pay online": opens the gateway (or the practice checkout). */
    public function payOnline(Request $request, EventBooking $eventBooking, EventPayment $eventPayment)
    {
        $res = \App\Domain\EventOnlinePay::start($this->step($request, $eventBooking, $eventPayment));

        return $res['ok'] ? redirect()->away($res['url']) : back()->with('error', $res['error']);
    }

    /** Back from the gateway: check the invoice right away (the webhook does the same). */
    public function onlineReturn(Request $request, EventBooking $eventBooking, EventPayment $eventPayment)
    {
        $p = $this->step($request, $eventBooking, $eventPayment);
        if ($request->boolean('cancelled')) {
            return redirect()->route('events.show', $eventBooking)->with('error', 'The online payment was not completed. You can try again or pay by transfer.');
        }
        $r = \App\Domain\EventOnlinePay::sync($p);

        return redirect()->route('events.show', $eventBooking)->with(in_array($r, ['paid', 'done'], true) ? 'success' : 'error', match ($r) {
            'paid', 'done' => 'Payment received. Thank you!',
            'review' => 'Payment received; '.$eventBooking->supplier->name.' will check the amount.',
            default => 'We have not received the payment yet. If you paid, it will show here in a few minutes.',
        });
    }

    /** Built-in practice checkout (no real money; PAYMONGO_FAKE, never in production). */
    public function practice(Request $request, EventBooking $eventBooking, EventPayment $eventPayment)
    {
        $p = $this->step($request, $eventBooking, $eventPayment);
        abort_unless(\App\Domain\EventOnlinePay::mode() === 'practice' && $p->gateway === 'practice', 404);

        return view('customer.event-practice', ['b' => $eventBooking, 'p' => $p]);
    }

    public function practicePay(Request $request, EventBooking $eventBooking, EventPayment $eventPayment)
    {
        $p = $this->step($request, $eventBooking, $eventPayment);
        abort_unless(\App\Domain\EventOnlinePay::mode() === 'practice' && $p->gateway === 'practice', 404);
        if ($request->input('result') === 'fail') {
            return redirect()->route('events.show', $eventBooking)->with('error', 'Practice payment failed (nothing was charged). Try again or pay by transfer.');
        }
        \App\Domain\EventOnlinePay::settle($p, $p->gateway_ref, $p->amount, 'practice checkout');

        return redirect()->route('events.show', $eventBooking)->with('success', 'Practice payment received (no real money).');
    }

    public function review(Request $request, EventBooking $eventBooking)
    {
        $b = $this->mine($request, $eventBooking);
        $v = $request->validate([
            'rating' => 'required|integer|min:1|max:5', 'comment' => 'required|string|min:'.\App\Domain\Reviews::MIN_COMMENT.'|max:2000',
            'photos' => 'nullable|array|max:'.\App\Domain\Reviews::MAX_PHOTOS, 'photos.*' => 'image|max:'.\App\Domain\Reviews::MAX_PHOTO_KB,
        ], ['rating.required' => 'Choose a star rating.']);
        $res = Events::review($b, $request->user(), (int) $v['rating'], $v['comment'], (array) $request->file('photos', []));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Thank you for your review!' : $res['error']);
    }

    public function proof(Request $request, EventBooking $eventBooking, EventPayment $eventPayment)
    {
        $b = $this->mine($request, $eventBooking);
        abort_unless($eventPayment->event_booking_id === $b->id && $eventPayment->proof_path && Storage::disk('local')->exists($eventPayment->proof_path), 404);

        return Storage::disk('local')->response($eventPayment->proof_path);
    }
}
