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
        $sup = $request->user()->shop();
        $sid = $sup->id;
        $tab = array_key_exists($request->query('tab'), self::GROUPS) ? $request->query('tab') : 'pending';
        $q = mb_strtolower((string) $request->query('q', ''));
        // one branch's rentals (multi-branch shops), or all of them
        // only branches this user may see; the dashboard's branch (switcher) applies unless one is picked here
        $branches = \App\Support\BranchScope::choices();
        $branch = $branches->firstWhere('id', (int) $request->query('branch'));
        $all = RentalRequest::forShop($sup, true, ! $branch)->when($branch, fn ($w) => $w->where(fn ($x) => $x->where('branch_id', $branch->id)
            ->orWhereHas('transfers', fn ($t) => $t->where('from_location_id', $branch->id)->where('status', '!=', 'cancelled'))))->with(['customer', 'payments', 'branch'])->get();
        $list = $all->filter(fn ($r) => ! self::GROUPS[$tab] || in_array($r->status, self::GROUPS[$tab], true))
            ->filter(fn ($r) => ! $q || str_contains(mb_strtolower($r->code.' '.$r->event_name.' '.$r->customer->name), $q));
        $list = in_array($tab, ['pending', 'upcoming'], true) ? $list->sortBy('start_date') : $list->sortByDesc('created_at');

        return view('supplier.requests', [
            'list' => $list->values(), 'tab' => $tab, 'q' => $request->query('q', ''), 'branches' => $branches, 'branch' => $branch,
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
            'due' => Rentals::paymentDue($r), 'reminderText' => Rentals::paymentReminderText($r),
            'canRemind' => in_array($r->status, Finance::ACTIVE, true) && $u->canShop('supplier.requests.remind'),
            'peak' => in_array($r->status, ['return_pending', 'ready', 'accepted', 'in_progress', 'out_delivery', 'ready_pickup'], true) ? Rentals::peakUnits($r) : [], 'sug' => Finance::suggestedNext($r),
        ]);
    }


    /** Chat with the customer: new messages as JSON (polling), or post one (JSON from the page, form fallback). */
    /** Messages with the customer about this rental, on their own page. */
    public function chatPage(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $chat = ['rows' => \App\Domain\Chat::rows($r, 'supplier'), 'unread' => \App\Domain\Chat::unread($r, 'supplier')];
        \App\Domain\Chat::markSeen($r, 'supplier');

        return view('supplier.request-messages', ['r' => $r, 'chat' => $chat]);
    }

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

    /** Apply one of the shop's custom discounts for this customer (before any payment). */
    public function applyCustomDiscount(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['discount_id' => 'required|integer'], ['discount_id.required' => 'Choose a discount.']);
        $res = \App\Domain\CustomDiscounts::apply($r, (int) $data['discount_id'], $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Discount applied: −'.money($res['amount']).'. '.$r->customer->firstName().' now pays '.money($res['total']).' (was '.money($res['before']).').' : $res['error']);
    }

    public function removeCustomDiscount(Request $request, RentalRequest $rentalRequest)
    {
        $res = \App\Domain\CustomDiscounts::remove($this->own($request, $rentalRequest), $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Discount removed. The total is back to '.money($res['total']).'.' : $res['error']);
    }

    /** Change the inventory source of one line. */
    public function source(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['line' => 'required|string|max:20', 'location' => 'required|integer']);
        if (! \App\Support\BranchScope::covers((int) $data['location'])) {
            return back()->with('error', 'You can only move a rental to one of your own branches.');
        }
        $res = Rentals::setLineSource($r, $data['line'], (int) $data['location']);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $r->code.' is now fulfilled by '.($r->fresh()->branch?->name ?? 'the new branch').'.' : $res['error']);
    }

    /** Accept or reject a customer's earlier-delivery request for one location (App\Domain\DeliverySchedule). */
    public function early(Request $request, RentalRequest $rentalRequest, string $dest, string $decision)
    {
        $r = $this->own($request, $rentalRequest);
        $res = \App\Domain\DeliverySchedule::decide($r, $dest, $decision === 'accept', $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? ($decision === 'accept' ? 'Earlier delivery accepted. The customer was told.' : 'Earlier delivery rejected. The standard time stays; the customer was told.') : $res['error']);
    }

    /** Work out again where a combined rental's items come from (a lending branch closed or ran short). */
    public function replan(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $res = \App\Domain\Branches::reassign($r, (int) $r->branch_id);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Branches re-planned for '.$r->code.'.' : $res['error']);
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

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $r->code.' accepted. '.$r->customer->firstName().' was told to pay within '.\App\Domain\Rentals::PAY_HOURS.' hours; the items are reserved once that payment comes in.' : $res['error']);
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
        $data = $request->validate(['to' => 'required|string', 'receivedBy' => 'nullable|string|max:120', 'releaseDate' => 'nullable|date', 'releaseQty' => 'nullable|string|max:500', 'note' => 'nullable|string|max:500',
            'release_photos' => 'nullable|array|max:6', 'release_photos.*' => 'image|max:8192']);
        if ($data['to'] === 'ongoing') {
            $data['releasePhotos'] = array_map(fn ($f) => $f->store('releases', 'public'), (array) $request->file('release_photos', []));
        }
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
        if (! $res['ok'] && $photos) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($photos); // nothing was saved: don't keep the uploads
        }
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }
        $r = $r->fresh();
        $charge = (float) ($r->inspection['compensation'] ?? $r->fin()['compensationDue'] ?? 0);

        return back()->with('success', $charge > 0
            ? 'Inspection saved. '.$r->customer->firstName().' has 48 hours to accept or dispute the '.money($charge).' charge; damaged units are kept out of your stock until fixed.'
            : 'Inspection saved. Everything came back fine; you can settle the deposit now.');
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

    /** Remind the customer about what they still owe (notification + chat message). */
    public function remind(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['message' => 'nullable|string|max:'.\App\Domain\Chat::MAX]);
        $res = Rentals::remindPayment($r, $request->user(), (string) ($data['message'] ?? ''));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Payment reminder sent to '.$r->customer->name.'. It is also in your messages with them.' : $res['error']);
    }

    /** Record a payment received by the shop (reference recordCash): verified at once, receipt issued. */
    public function recordCash(Request $request, RentalRequest $rentalRequest)
    {
        $r = $this->own($request, $rentalRequest);
        $data = $request->validate(['type' => 'required|string', 'method' => 'nullable|in:cash,bank,debit', 'amount' => 'required|numeric|min:1', 'date' => 'required|date|before_or_equal:today', 'reference' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:255']);
        $res = Rentals::cashierRecord($r, array_merge($data, ['method' => $data['method'] ?? 'cash']), $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Payment recorded and verified. Receipt '.$res['payment']->receipt_no.' issued.' : $res['error']);
    }
}
