<?php

namespace App\Http\Controllers\Admin;

use App\Domain\EventTypes;
use App\Http\Controllers\Business\EventTypeController as BusinessEventTypes;
use App\Http\Controllers\Controller;
use App\Models\EventType;
use App\Models\SupplierEventType;
use Illuminate\Http\Request;

/**
 * PartyRent: the event type catalog, and checking what each event company says it can handle
 * (approve with limits, turn down, pause / resume one type). Reviews need applications.review; the catalog
 * categories.manage.
 */
class EventTypeController extends Controller
{
    private function need(Request $r, string $perm): void
    {
        abort_unless($r->user()->hasPerm($perm), 403, 'You do not have permission for this page.');
    }

    public function index(Request $request)
    {
        $this->need($request, 'applications.review');
        $tab = in_array($request->query('tab'), ['review', 'businesses', 'catalog'], true) ? $request->query('tab') : 'review';
        $waiting = SupplierEventType::where(fn ($q) => $q->where('status', 'pending')->orWhere('raise_pending', true))->with(['supplier', 'type'])->oldest('updated_at')->get();
        $all = SupplierEventType::whereIn('status', ['verified', 'paused', 'rejected'])->with(['supplier', 'type', 'reviewer'])->get()->sortBy(fn ($r) => [$r->supplier->name, $r->type->name]);
        $types = EventType::orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")->orderBy('sort')->orderBy('name')->withCount(['businesses as verified_count' => fn ($q) => $q->where('status', 'verified')])->with('requester')->get();

        return view('admin.event-types', compact('tab', 'waiting', 'all', 'types') + ['counts' => ['review' => $waiting->count(), 'businesses' => $all->count(), 'catalog' => $types->where('status', 'requested')->count()]]);
    }

    public function review(Request $request, SupplierEventType $row)
    {
        $this->need($request, 'applications.review');
        $v = $request->validate(['decision' => 'required|in:approve,reject', 'max_guests' => 'nullable|integer|min:1|max:100000', 'per_day' => 'nullable|integer|min:1|max:20', 'reason' => 'nullable|string|max:500']);
        $res = EventTypes::review($row, $request->user(), $v['decision'] === 'approve', $v['max_guests'] ?? null, $v['per_day'] ?? null, $v['reason'] ?? null);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $row->supplier->name.' · '.$row->type->name.($v['decision'] === 'approve' ? ' verified.' : ' sent back with your reason.') : $res['error']);
    }

    public function pause(Request $request, SupplierEventType $row)
    {
        $this->need($request, 'applications.review');
        $v = $request->validate(['pause' => 'required|boolean', 'reason' => 'nullable|string|max:500']);
        $res = EventTypes::setPaused($row, $request->user(), (bool) $v['pause'], $v['reason'] ?? null);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $row->supplier->name.' · '.$row->type->name.((bool) $v['pause'] ? ' paused.' : ' resumed.') : $res['error']);
    }

    public function file(Request $request, SupplierEventType $row, string $which, int $i)
    {
        $this->need($request, 'applications.review');

        return BusinessEventTypes::send($row, $which, $i);
    }

    /** Add a type, or edit one (name, description, typical guests, parts, services, proof). */
    public function save(Request $request, ?EventType $eventType = null)
    {
        $this->need($request, 'categories.manage');
        $v = $request->validate([
            'name' => 'required|string|min:3|max:60|unique:event_types,name'.($eventType ? ','.$eventType->id : ''),
            'description' => 'nullable|string|max:500', 'min_guests' => 'nullable|integer|min:1|max:100000', 'max_guests' => 'nullable|integer|min:1|max:100000'.($request->filled('min_guests') ? '|gte:min_guests' : ''),
            'parts' => 'nullable|string|max:2000', 'services' => 'nullable|string|max:1000', 'proofs' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,hidden',
        ]);
        $lines = fn ($s) => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $s)), fn ($x) => $x !== ''));
        // parts: one per line, "(optional)" marks an optional one
        $parts = array_map(fn ($p) => ['name' => mb_substr(trim(str_ireplace('(optional)', '', $p)), 0, 60), 'optional' => stripos($p, '(optional)') !== false, 'offset' => 0], array_slice(array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) ($v['parts'] ?? ''))))), 0, 12));
        $data = ['name' => trim($v['name']), 'description' => $v['description'] ?? null, 'min_guests' => $v['min_guests'] ?? null, 'max_guests' => $v['max_guests'] ?? null,
            'parts' => $parts ?: [['name' => 'Event', 'optional' => false, 'offset' => 0]], 'services' => array_slice($lines($v['services'] ?? ''), 0, 15), 'proofs' => array_slice($lines($v['proofs'] ?? ''), 0, 6)];
        if ($eventType) {
            // keep each part's timing when only names change
            $old = collect((array) $eventType->parts)->keyBy('name');
            $data['parts'] = array_map(fn ($p) => ['offset' => $old[$p['name']]['offset'] ?? 0] + $p, $data['parts']);
            $eventType->update($data + ['status' => $eventType->status === 'requested' ? 'requested' : ($v['status'] ?? $eventType->status)]);
        } else {
            $eventType = EventType::create($data + ['status' => 'active', 'sort' => 500]);
        }
        \App\Domain\Notifier::audit('Saved event type', $eventType->name, $request->user());

        return back()->with('success', $eventType->name.' saved.');
    }

    public function decide(Request $request, EventType $eventType)
    {
        $this->need($request, 'categories.manage');
        $v = $request->validate(['add' => 'required|boolean', 'reason' => 'nullable|string|max:500']);
        $res = EventTypes::decideRequest($eventType, $request->user(), (bool) $v['add'], $v['reason'] ?? null);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $eventType->name.((bool) $v['add'] ? ' added to the list.' : ' not added.') : $res['error']);
    }
}
