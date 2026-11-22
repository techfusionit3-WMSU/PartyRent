<?php

namespace App\Http\Controllers\Business;

use App\Domain\BusinessProfile;
use App\Models\EventPackage;
use Illuminate\Http\Request;

/** Event packages: what a business sells, priced per event for a guest range. */
class PackageController extends BusinessController
{
    public function index(Request $request)
    {
        $s = $this->biz($request);

        return view('business.packages', ['s' => $s, 'list' => $s->eventPackages()->latest()->get(), 'verified' => \App\Domain\EventTypes::verified($s)]);
    }

    public function create(Request $request)
    {
        $s = $this->biz($request);

        $verified = \App\Domain\EventTypes::verified($s);
        if ($verified->isEmpty()) {
            return redirect()->route('business.event-types')->with('warn', 'Get an event type verified first: packages can only be offered for kinds of events PartyRent has checked.');
        }

        return view('business.package-form', ['s' => $s, 'verified' => $verified, 'k' => new EventPackage(['active' => true, 'events' => [], 'inclusions' => [], 'photos' => []])]);
    }

    public function edit(Request $request, EventPackage $eventPackage)
    {
        $s = $this->biz($request);
        abort_unless($eventPackage->supplier_id === $s->id, 404);

        return view('business.package-form', ['s' => $s, 'k' => $eventPackage, 'verified' => \App\Domain\EventTypes::verified($s)]);
    }

    public function store(Request $request)
    {
        $s = $this->biz($request);
        $k = $s->eventPackages()->create($this->data($request, null));

        return redirect()->route('business.packages')->with('success', $k->name.' saved. Customers can now ask for a quotation on it.');
    }

    public function update(Request $request, EventPackage $eventPackage)
    {
        $s = $this->biz($request);
        abort_unless($eventPackage->supplier_id === $s->id, 404);
        $eventPackage->update($this->data($request, $eventPackage));

        return redirect()->route('business.packages')->with('success', $eventPackage->name.' updated.');
    }

    public function toggle(Request $request, EventPackage $eventPackage)
    {
        $s = $this->biz($request);
        abort_unless($eventPackage->supplier_id === $s->id, 404);
        $eventPackage->update(['active' => ! $eventPackage->active]);

        return back()->with('success', $eventPackage->name.($eventPackage->active ? ' is visible to customers again.' : ' is hidden from customers.'));
    }

    private function data(Request $request, ?EventPackage $k): array
    {
        $v = $request->validate([
            'name' => 'required|string|min:3|max:120', 'description' => 'required|string|min:20|max:3000',
            'price' => 'required|numeric|min:1|max:100000000',
            'min_guests' => 'nullable|integer|min:1|max:100000', 'max_guests' => 'nullable|integer|min:1|max:100000'.($request->filled('min_guests') ? '|gte:min_guests' : ''),
            'events' => 'required|array|min:1|max:15', 'events.*' => 'string|max:60',
            'inclusions' => 'required|array|min:1|max:40', 'inclusions.*' => 'string|max:120',
            'photos' => 'nullable|array|max:6', 'photos.*' => 'image|max:8192',
            'photos_keep' => 'nullable|array', 'photos_keep.*' => 'string|max:255',
            'active' => 'nullable|boolean',
        ], [
            'inclusions.required' => 'List at least one thing the package includes.',
            'events.required' => 'Choose at least one event type this package is for.',
            'max_guests.gte' => 'The maximum guests cannot be lower than the minimum.',
        ]);
        // only verified event types, within their verified guest count
        $events = array_values(array_unique(array_map('strval', $v['events'])));
        if ($e = \App\Domain\EventTypes::packageError($request->user()->shop(), $events, isset($v['max_guests']) ? (int) $v['max_guests'] : (isset($v['min_guests']) ? (int) $v['min_guests'] : null))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['events' => $e]);
        }
        $keep = array_values(array_intersect((array) ($k?->photos ?? []), (array) $request->input('photos_keep', [])));
        $new = array_map(fn ($f) => $f->store('event-packages/'.$request->user()->shop()->id, 'public'), (array) $request->file('photos', []));
        $photos = array_slice(array_merge($keep, $new), 0, 6);

        return [
            'name' => trim($v['name']), 'description' => trim($v['description']), 'price' => round((float) $v['price'], 2),
            'min_guests' => $v['min_guests'] ?? null, 'max_guests' => $v['max_guests'] ?? null,
            'events' => $events,
            'inclusions' => BusinessProfile::tags($v['inclusions'], [], 40),
            'photos' => $photos, 'active' => $request->boolean('active', true),
        ];
    }
}
