<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Finance;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Models\RentalRequest;
use Illuminate\Http\Request;

/** A supplier only ever sees requests for its own shop. */
class RequestController extends Controller
{
    private function own(Request $request, RentalRequest $r): RentalRequest
    {
        abort_unless($r->supplier_id === $request->user()->shop()?->id, 404);
        $r->load(['payments', 'supplier', 'customer']);

        return $r;
    }

    public const GROUPS = [
        'pending' => ['pending'], 'upcoming' => ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'],
        'active' => ['ongoing', 'return_pending', 'inspection'], 'completed' => ['completed'], 'closed' => ['declined', 'cancelled'], 'all' => null,
    ];

    public function index(Request $request)
    {
        $sid = $request->user()->shop()->id;
        $tab = array_key_exists($request->query('tab'), self::GROUPS) ? $request->query('tab') : 'pending';
        $q = mb_strtolower((string) $request->query('q', ''));
        $all = RentalRequest::where('supplier_id', $sid)->with(['customer', 'payments'])->get();
        $list = $all->filter(fn ($r) => ! self::GROUPS[$tab] || in_array($r->status, self::GROUPS[$tab], true))
            ->filter(fn ($r) => ! $q || str_contains(mb_strtolower($r->code.' '.$r->event_name.' '.$r->customer->name), $q));
        $list = in_array($tab, ['pending', 'upcoming'], true) ? $list->sortBy('start_date') : $list->sortByDesc('created_at');

        return view('supplier.requests', [
            'list' => $list->values(), 'tab' => $tab, 'q' => $request->query('q', ''),
            'counts' => array_map(fn ($st) => $all->filter(fn ($r) => ! $st || in_array($r->status, $st, true))->count(), self::GROUPS),
        ]);
    }

    public function show(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $u = $request->user();
        $chk = inv()->check($r->lines, $r->id);
        $f = $r->fin();
        $locs = $r->supplier->locations;
        $chat = ['rows' => \App\Domain\Chat::rows($r, 'supplier'), 'unread' => \App\Domain\Chat::unread($r, 'supplier')];
        \App\Domain\Chat::markSeen($r, 'supplier');

        return view('supplier.request', [
            'r' => $r, 'chat' => $chat, 'c' => $r->customer, 'f' => $f, 'chk' => $chk, 'conflicts' => $r->status === 'pending' ? $chk['bad'] : [],
            'showAvail' => in_array($r->status, ['pending', 'accepted', 'in_progress'], true), 'locs' => $locs,
            'canAlloc' => in_array($r->status, ['pending', 'accepted', 'in_progress'], true) && ($u->orgCan('orders.manage') || $u->orgCan('inventory.manage')) && $locs->count() > 1,
            'finOK' => Rentals::canFinance($r->supplier_id, 'payments.verify', $u), 'canRecord' => in_array($r->status, Finance::ACTIVE, true) && Rentals::canFinance($r->supplier_id, 'payments.record', $u),
            'next' => Rentals::nextSteps($r), 'review' => $r->status === 'pending' ? Rentals::acceptReview($r) : null,
            'checklist' => $r->status === 'inspection' ? Rentals::completionChecklist($r) : [],
            'custHist' => RentalRequest::where('customer_id', $r->customer_id)->get(['status']),
            'peak' => $r->status === 'return_pending' ? Rentals::peakUnits($r) : [], 'sug' => Finance::suggestedNext($r),
        ]);
    }


    /** Chat with the customer: new messages as JSON (polling), or post one (JSON from the page, form fallback). */
    public function messages(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $rows = \App\Domain\Chat::rows($r, 'supplier', (int) $request->query('after', 0));
        if ($rows) {
            \App\Domain\Chat::markSeen($r, 'supplier');
        }

        return response()->json(['ok' => true, 'messages' => $rows]);
    }

    public function sendMessage(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $res = \App\Domain\Chat::post($r, $request->user(), 'supplier', (string) $request->input('body', ''));
        if ($request->expectsJson()) {
            return response()->json($res['ok'] ? ['ok' => true, 'messages' => \App\Domain\Chat::rows($r, 'supplier', (int) $request->input('after', 0))] : $res, $res['ok'] ? 200 : 422);
        }

        return redirect()->to(url()->previous().'#chat')->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Message sent.' : $res['error']);
    }

    /** Change the inventory source of one line. */
    public function source(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['line' => 'required|string|max:20', 'location' => 'required|integer']);
        $res = Rentals::setLineSource($r, $data['line'], (int) $data['location']);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Inventory source updated.' : $res['error']);
    }

    /** Approve or reject the customer's installment schedule. */
    public function schedule(Request $request, RentalRequest $rentalRequest, string $decision)
    {
        $r = $this->own($request, $rentalRequest);
        $approve = $decision === 'approve';
        $res = Rentals::reviewInstallmentPlan($r, $approve, $request->input('reason'), $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? ($approve ? 'Installment schedule approved.' : 'Schedule rejected. Customer notified.') : $res['error']);
    }
    public function accept(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $res = Rentals::acceptRequest($r, trim((string) $request->input('note', '')) ?: null);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $r->code.' booked. Items are set aside and the customer was notified.' : $res['error']);
    }

    public function decline(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $res = Rentals::declineRequest($r, $data['reason']);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Request declined.' : $res['error']);
    }

    public function advance(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['to' => 'required|string', 'receivedBy' => 'nullable|string|max:120', 'releaseDate' => 'nullable|date', 'releaseQty' => 'nullable|string|max:500', 'note' => 'nullable|string|max:500']);
        $res = Rentals::advance($r, $data['to'], $data);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Status updated to '.RentalRequest::statusLabel($data['to']).'.' : $res['error']);
    }

    public function inspection(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $request->validate(['returnDate' => 'required|date', 'notes' => 'nullable|string|max:2000', 'items' => 'array', 'photos' => 'array|max:4', 'photos.*' => 'image|max:8192']);
        $photos = [];
        foreach ((array) $request->file('photos', []) as $file) {
            $photos[] = $file->store('inspections', 'public');
        }
        $res = Rentals::recordInspection($r, ['returnDate' => $request->input('returnDate'), 'notes' => $request->input('notes'), 'items' => (array) $request->input('items', []), 'photos' => $photos, 'confirmed' => $request->boolean('confirmed')]);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Inspection confirmed. Inventory updated by condition.' : $res['error']);
    }

    public function settle(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $res = Rentals::settleDeposit($r, (string) $request->input('mode', 'settled'));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Deposit settled'.(($res['refund'] ?? 0) > 0 ? ' — '.money($res['refund']).' refunded.' : '.') : $res['error']);
    }

    public function complete(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $res = Rentals::completeRental($r);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Rental completed. History is preserved.' : $res['error']);
    }

    public function notes(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $r->update(['supplier_notes' => mb_substr((string) $request->input('notes', ''), 0, 2000)]);

        return back()->with('success', 'Notes saved.');
    }

    /** Record a payment received by the shop (reference recordCash): verified at once, receipt issued. */
    public function recordCash(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['type' => 'required|string', 'amount' => 'required|numeric|min:1', 'date' => 'required|date|before_or_equal:today', 'reference' => 'required|string|max:80', 'notes' => 'nullable|string|max:255']);
        $res = Rentals::cashierRecord($r, array_merge($data, ['method' => $request->input('method', 'cash')]), $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Payment recorded and verified. Receipt '.$res['payment']->receipt_no.' issued.' : $res['error']);
    }
}
