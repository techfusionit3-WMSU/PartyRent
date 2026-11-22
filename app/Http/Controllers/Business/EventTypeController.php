<?php

namespace App\Http\Controllers\Business;

use App\Domain\EventTypes;
use App\Models\EventType;
use App\Models\SupplierEventType;
use Illuminate\Http\Request;

/** The kinds of events a business handles: apply per type with proof, see the verified limits, ask for a new type. */
class EventTypeController extends BusinessController
{
    public function index(Request $request)
    {
        $s = $this->biz($request);
        $mine = $s->eventTypes()->with('type')->get()->keyBy('event_type_id');

        return view('business.event-types', [
            's' => $s, 'mine' => $mine,
            'types' => EventType::active()->get(),
            'requested' => EventType::where('requested_by', $s->id)->where('status', 'requested')->get(),
            'open' => (int) $request->query('apply'),
        ]);
    }

    public function apply(Request $request, EventType $eventType)
    {
        $s = $this->biz($request);
        $v = $request->validate([
            'max_guests' => 'required|integer|min:1|max:100000', 'per_day' => 'required|integer|min:1|max:20',
            'past_events' => 'required|integer|min:0|max:100000', 'note' => 'nullable|string|max:1000',
            'photos' => 'nullable|array|max:8', 'photos.*' => 'image|max:8192', 'photos_keep' => 'nullable|array', 'photos_keep.*' => 'string|max:255',
            'docs' => 'nullable|array', 'docs.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ], [
            'max_guests.required' => 'Enter the biggest guest count you can handle for this kind of event.',
            'per_day.required' => 'Enter how many of these events you can run on the same day.',
            'past_events.required' => 'Enter how many of these events you have done (0 if none yet).',
        ]);
        $proofs = EventTypes::proofsFor($eventType);
        $docs = [];
        foreach ((array) $request->file('docs', []) as $i => $f) {
            if (isset($proofs[$i])) {
                $docs[$proofs[$i]] = $f;
            }
        }
        $res = EventTypes::apply($s, $request->user(), $eventType, $v, (array) $request->file('photos', []), $docs, (array) $request->input('photos_keep', []));
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error'])->with('apply', $eventType->id);
        }
        $msg = $res['row']->status === 'verified'
            ? ($res['raise'] ? 'Sent. PartyRent will check the higher limit; until then your current limits apply.' : $eventType->name.' details saved.')
            : 'Sent for review. PartyRent checks your proof, usually within 1–2 business days, and tells you the limits you are verified for.';

        return redirect()->route('business.event-types')->with('success', $msg);
    }

    public function request(Request $request)
    {
        $s = $this->biz($request);
        $v = $request->validate(['name' => 'required|string|min:3|max:60', 'note' => 'nullable|string|max:500']);
        $res = EventTypes::requestType($s, $v['name'], $v['note'] ?? null);

        return $this->done($res, 'Asked PartyRent to add "'.trim($v['name']).'". You will be told when you can apply for it.');
    }

    /** A proof file of this business (photo or permit), for its own people. */
    public function file(Request $request, SupplierEventType $row, string $which, int $i)
    {
        abort_unless($row->supplier_id === $this->biz($request)->id, 404);

        return self::send($row, $which, $i);
    }

    public static function send(SupplierEventType $row, string $which, int $i)
    {
        $list = array_values($which === 'doc' ? (array) $row->docs : (array) $row->photos);
        $path = $list[$i] ?? null;
        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($path);
    }
}
