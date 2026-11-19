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
        $pkg = $request->query('package') ? EventPackage::where('supplier_id', $supplier->id)->where('active', true)->find($request->query('package')) : null;

        return view('customer.event-form', ['s' => $supplier, 'pkg' => $pkg, 'set' => $supplier->bizSettings(), 'packages' => $supplier->eventPackages()->where('active', true)->get()]);
    }

    public function store(Request $request, Supplier $supplier)
    {
        abort_unless($supplier->isOrganization(), 404);
        $v = $request->validate([
            'event_package_id' => 'nullable|integer', 'event_type' => 'required|string|max:60',
            'event_date' => 'required|date|after:today', 'start_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'guests' => 'required|integer|min:1|max:100000', 'venue' => 'required|string|min:5|max:255', 'city' => 'nullable|string|max:120',
            'budget' => 'nullable|numeric|min:0|max:100000000', 'notes' => 'nullable|string|max:2000',
        ], ['venue.required' => 'Enter where the event will be held.']);
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

    public function proof(Request $request, EventBooking $eventBooking, EventPayment $eventPayment)
    {
        $b = $this->mine($request, $eventBooking);
        abort_unless($eventPayment->event_booking_id === $b->id && $eventPayment->proof_path && Storage::disk('local')->exists($eventPayment->proof_path), 404);

        return Storage::disk('local')->response($eventPayment->proof_path);
    }
}
