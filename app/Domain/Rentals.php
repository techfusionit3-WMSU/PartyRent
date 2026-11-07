<?php

namespace App\Domain;

use App\Models\CartLine;
use App\Models\Hold;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\RequestEvent;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Business workflows (port of js/core/services.js). Every state change goes through
 * here so inventory, payments, notifications and the audit trail stay consistent.
 * Each method returns ['ok' => bool, 'error' => string|null, ...].
 */
class Rentals
{
    public const FLOW = [
        'accepted' => ['in_progress'],
        'in_progress' => ['ready_pickup', 'out_delivery'],
        'ready_pickup' => ['ongoing'],
        'out_delivery' => ['ongoing'],
        'ongoing' => ['return_pending'],
        'return_pending' => ['inspection'],
        'inspection' => ['completed'],
    ];

    public const ACTION_LABEL = [
        'in_progress' => 'Start preparing items', 'ready_pickup' => 'Mark ready for pickup', 'out_delivery' => 'Mark out for delivery',
        'ongoing' => 'Record release to customer', 'return_pending' => 'Mark return pending', 'inspection' => 'Record returned items', 'completed' => 'Complete rental',
    ];

    private static function fail(string $error, array $extra = []): array
    {
        return array_merge(['ok' => false, 'error' => $error], $extra);
    }

    private static function ok(array $extra = []): array
    {
        return array_merge(['ok' => true, 'error' => null], $extra);
    }

    private static function link(string $route, mixed $params = []): string
    {
        return route($route, $params, false);
    }

    private static function history(RentalRequest $r, string $status, ?string $note = null): void
    {
        RequestEvent::create(['rental_request_id' => $r->id, 'status' => $status, 'user_id' => auth()->id(), 'note' => $note ?: null, 'created_at' => now()]);
    }

    private static function changed(RentalRequest $r): void
    {
        $r->finCache = null;
        inv()->flush();
    }

    /* ================= CART (Rental List) ================= */

    /** @return array<int, array<int, array>> supplier_id => lines */
    public static function cartGroups(User $u): array
    {
        $groups = [];
        foreach (CartLine::where('user_id', $u->id)->orderBy('id')->get() as $c) {
            $groups[$c->supplier_id][] = $c->toLine();
        }

        return $groups;
    }

    public static function cartCount(User $u): int
    {
        return CartLine::where('user_id', $u->id)->count();
    }

    public static function cartLinesFor(User $u, int $supplierId): array
    {
        return CartLine::where('user_id', $u->id)->where('supplier_id', $supplierId)->orderBy('id')->get()->map->toLine()->all();
    }

    /** Add a line, merging with an existing line for the same item. Checks stock combined with the supplier's other cart lines. */
    public static function addToCart(User $u, string $type, int $refId, array $days): array
    {
        $item = $type === 'package' ? Package::find($refId) : Product::find($refId);
        if (! $item || ($type === 'package' ? ! $item->active : $item->status !== 'published')) {
            return self::fail('This listing is not available.');
        }
        if ($item->supplier->owner_id === $u->id) {
            return self::fail('You cannot rent from your own shop.');
        }
        $days = self::cleanDays($days);
        if (! $days) {
            return self::fail('Set a quantity for at least one date.');
        }
        if (min(array_keys($days)) < today_iso()) {
            return self::fail('You cannot rent for a past date.');
        }
        if (count($days) > (int) Settings::get('maxRentalDays', 30)) {
            return self::fail('Maximum of '.Settings::get('maxRentalDays', 30).' rental dates per item.');
        }
        $existing = self::cartLinesFor($u, $item->supplier_id);
        $chk = inv()->check(array_merge($existing, [['id' => '__new', 'type' => $type, 'ref_id' => $refId, 'days' => $days]]));
        if (! $chk['ok']) {
            $bad = implode(', ', array_map(fn ($b) => fmt_date($b['date']).' (available '.$b['available'].')', $chk['bad']));

            return self::fail('Not enough stock on '.$bad.($existing ? ', including items already in your Rental List.' : '.'));
        }
        $line = CartLine::where('user_id', $u->id)->where('type', $type)->where('ref_id', $refId)->first();
        if ($line) {
            $merged = $line->days;
            foreach ($days as $d => $q) {
                $merged[$d] = (int) ($merged[$d] ?? 0) + $q;
            }
            ksort($merged);
            $line->update(['days' => $merged]);
        } else {
            CartLine::create(['user_id' => $u->id, 'supplier_id' => $item->supplier_id, 'type' => $type, 'ref_id' => $refId, 'days' => $days]);
        }

        return self::ok(['name' => $item->name, 'supplier_id' => $item->supplier_id]);
    }

    /** Replace a cart line's per-day schedule (rows with qty 0 are kept so the customer can edit them). */
    public static function updateCartLine(User $u, CartLine $line, array $days): array
    {
        if ($line->user_id !== $u->id) {
            return self::fail('Not your rental list.');
        }
        $clean = [];
        foreach ($days as $d => $q) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) {
                $clean[$d] = max(0, (int) $q);
            }
        }
        if (count($clean) > (int) Settings::get('maxRentalDays', 30)) {
            return self::fail('Maximum of '.Settings::get('maxRentalDays', 30).' rental dates per item.');
        }
        ksort($clean);
        $line->update(['days' => $clean]);

        return self::ok();
    }

    public static function cleanDays(array $days): array
    {
        $out = [];
        foreach ($days as $d => $q) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) && (int) $q > 0) {
                $out[$d] = (int) $q;
            }
        }
        ksort($out);

        return $out;
    }

    /* ================= RENTAL REQUESTS ================= */

    public static function submitRequest(User $u, Supplier $s, array $form): array
    {
        $lines = [];
        $i = 1;
        foreach (self::cartLinesFor($u, $s->id) as $l) {
            $days = self::cleanDays($l['days']);
            if ($days) {
                $lines[] = ['id' => 'l'.$i++, 'type' => $l['type'], 'ref_id' => $l['ref_id'], 'days' => $days];
            }
        }
        if (! $lines) {
            return self::fail('There are no items for this supplier in your rental list.');
        }
        if ($s->owner_id === $u->id) {
            return self::fail('You cannot rent from your own shop.');
        }
        $allDates = [];
        foreach ($lines as $l) {
            $allDates = array_merge($allDates, array_keys($l['days']));
        }
        sort($allDates);
        if ($allDates[0] < today_iso()) {
            return self::fail('Some dates in your list are in the past. Please update them in your Rental List.');
        }
        $chk = inv()->check($lines);
        if (! $chk['ok']) {
            return self::fail('Some quantities exceed the available stock on the selected dates. Please adjust them.', ['check' => $chk]);
        }
        if ($form['fulfillment'] === 'delivery' && $s->fulfillment === 'pickup') {
            return self::fail($s->name.' offers pickup only.');
        }
        if ($form['fulfillment'] === 'pickup' && $s->fulfillment === 'delivery') {
            return self::fail($s->name.' offers delivery only.');
        }
        if (! in_array($form['arrangement'], $s->arrangements, true) || ! in_array($form['method'], $s->methods, true)) {
            return self::fail('Choose a payment arrangement and method this supplier accepts.');
        }

        $r = DB::transaction(function () use ($u, $s, $form, $lines, $allDates) {
            $r = new RentalRequest([
                'code' => 'PR-'.str_pad((string) Settings::nextSeq('request'), 4, '0', STR_PAD_LEFT),
                'customer_id' => $u->id, 'supplier_id' => $s->id,
                'event_name' => $form['eventName'], 'event_type' => $form['eventType'],
                'event_date' => $form['eventDate'] ?: $allDates[0],
                'start_date' => $allDates[0], 'end_date' => end($allDates),
                'fulfillment' => $form['fulfillment'],
                'address' => $form['fulfillment'] === 'delivery' ? ($form['address'] ?? '') : '',
                'contact_name' => $form['contactName'], 'contact_phone' => $form['contactPhone'],
                'instructions' => $form['instructions'] ?? '',
                'lines' => $lines, 'arrangement' => $form['arrangement'], 'method' => $form['method'],
                'status' => 'pending', 'supplier_notes' => '',
            ]);
            $r->setRelation('supplier', $s);
            $r->pricing = Pricing::quoteRequest($r);
            $r->save();
            self::history($r, 'pending', 'Request submitted');
            CartLine::where('user_id', $u->id)->where('supplier_id', $s->id)->delete();

            return $r;
        });
        self::changed($r);

        $conflicts = array_filter(inv()->check($lines)['rows'], fn ($x) => inv()->pendingOn($x['product_id'], $x['date'], $r->id) + $x['requested'] > $x['available']);
        Notifier::notify($s->owner_id, 'supplier', 'New rental request', $u->name.' sent '.$r->code.' ('.$r->event_name.') for '.fmt_range($r->start_date, $r->end_date).'.'.($conflicts ? ' ⚠ Overlaps other pending requests.' : ''), self::link('supplier.requests.show', $r));
        Notifier::notify($u, 'customer', 'Rental request submitted', $r->code.' was sent to '.$s->name.' and is pending review. You will be notified once they respond.', self::link('rentals.show', $r));

        return self::ok(['request' => $r]);
    }

    /** Customer cancellation, following the supplier's cancellation policy. */
    public static function cancelPolicy(RentalRequest $r): array
    {
        $s = $r->supplier;
        $days = (int) $s->pol('cancelDays', 0);
        if ($r->status === 'pending') {
            return ['allowed' => true, 'free' => true, 'note' => 'This request has not been accepted yet, so it can be cancelled at no cost.'];
        }
        if (in_array($r->status, ['accepted', 'in_progress'], true)) {
            $deadline = add_days($r->start_date, -$days);
            $free = today_iso() <= $deadline;

            return ['allowed' => true, 'free' => $free, 'deadline' => $deadline, 'note' => $free
                ? 'Free cancellation until '.fmt_date($deadline).'. Verified payments will be refunded by the supplier.'
                : 'The free cancellation period ended on '.fmt_date($deadline).'. Per policy: '.$s->pol('cancellation', '')];
        }

        return ['allowed' => false, 'free' => false, 'note' => 'Items have already been released or the rental is closed, so it can no longer be cancelled online. Please contact the supplier.'];
    }

    public static function cancelRequest(RentalRequest $r, string $reason): array
    {
        $pol = self::cancelPolicy($r);
        if (! $pol['allowed'] || in_array($r->status, ['declined', 'cancelled', 'completed'], true)) {
            return self::fail($pol['note']);
        }
        $r->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancel_free' => $pol['free']]);
        self::history($r, 'cancelled', $reason);
        self::changed($r);
        $fin = $r->fin();
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Customer cancellation', $r->customer->name.' cancelled '.$r->code.'. '.($fin['paidRental'] > 0 ? 'Verified payments of '.money($fin['paidRental']).' need refund review.' : 'No payments were made.'), self::link('supplier.requests.show', $r));
        Notifier::audit('Cancelled rental request', $r->code.' — '.($pol['free'] ? 'within free period' : 'after free period').($reason ? ' · '.$reason : ''));

        return self::ok();
    }

    /** Re-check inventory for every date, validate pricing and policy. */
    public static function acceptReview(RentalRequest $r): array
    {
        $chk = inv()->check($r->lines, $r->id);
        $fresh = Pricing::quoteRequest($r);
        $s = $r->supplier;
        $issues = [];
        if (! in_array($r->arrangement, $s->arrangements, true)) {
            $issues[] = 'Payment arrangement "'.Payment::ARR_LABEL[$r->arrangement].'" is no longer accepted.';
        }
        if (! in_array($r->method, $s->methods, true)) {
            $issues[] = 'Payment method "'.(Payment::METHOD_LABEL[$r->method] ?? $r->method).'" is no longer accepted.';
        }
        $schedule = Finance::schedule($r->pricing, today_iso(), $r->start_date, $r->method);

        return ['check' => $chk, 'fresh' => $fresh, 'priceChanged' => abs($fresh['total'] - $r->pricing['total']) > 0.009, 'policyIssues' => $issues, 'schedule' => $schedule, 'canAccept' => $chk['ok'] && $r->status === 'pending'];
    }

    public static function acceptRequest(RentalRequest $r, ?string $note): array
    {
        // Serialize acceptances per supplier so two staff members can't double-book the same stock.
        $result = DB::transaction(function () use ($r, $note) {
            Supplier::whereKey($r->supplier_id)->lockForUpdate()->first();
            $r->refresh();
            if ($r->status !== 'pending') {
                return self::fail('Only pending requests can be accepted.');
            }
            inv()->flush();
            $rev = self::acceptReview($r);
            if (! $rev['check']['ok']) {
                return self::fail('Not enough stock on '.implode('; ', array_map(fn ($b) => fmt_date($b['date']).' ('.inv()->product($b['product_id'])->name.': need '.$b['requested'].', available '.$b['available'].')', $rev['check']['bad'])), ['conflict' => $rev['check']['bad']]);
            }
            $r->update(['status' => 'accepted', 'accepted_at' => today_iso(), 'supplier_notes' => $note ?: $r->supplier_notes]);
            self::history($r, 'accepted', $note ?: 'Request accepted');

            return self::ok();
        });
        if (! $result['ok']) {
            if (! empty($result['conflict'])) {
                Notifier::notify($r->supplier->owner_id, 'supplier', 'Inventory conflict', $r->code.' cannot be accepted — stock is insufficient on '.implode(', ', array_map(fn ($b) => fmt_date($b['date']), $result['conflict'])).'.', self::link('supplier.requests.show', $r));
            }

            return $result;
        }
        self::changed($r);
        $first = $r->fin()['schedule'][0] ?? null;
        Notifier::notify($r->customer_id, 'customer', 'Request accepted 🎉', $r->supplier->name.' accepted '.$r->code.'. '.($first ? $first['label'].' of '.money($first['amount']).' is due by '.fmt_date($first['due']).'.' : ''), self::link('rentals.show', $r));
        Notifier::audit('Accepted rental request', $r->code.' · '.money($r->pricing['total']));

        return self::ok();
    }

    public static function declineRequest(RentalRequest $r, string $reason): array
    {
        if ($r->status !== 'pending') {
            return self::fail('Only pending requests can be declined.');
        }
        $r->update(['status' => 'declined', 'decline_reason' => $reason]);
        self::history($r, 'declined', $reason);
        self::changed($r);
        Notifier::notify($r->customer_id, 'customer', 'Request declined', $r->supplier->name.' declined '.$r->code.($reason ? ': '.$reason : '.'), self::link('rentals.show', $r));
        Notifier::audit('Declined rental request', $r->code.($reason ? ' — '.$reason : ''));

        return self::ok();
    }

    /** Allowed next steps for a request (respecting delivery vs pickup). */
    public static function nextSteps(RentalRequest $r): array
    {
        return array_values(array_filter(self::FLOW[$r->status] ?? [], fn ($st) => ! ($st === 'ready_pickup' && $r->fulfillment !== 'pickup') && ! ($st === 'out_delivery' && $r->fulfillment !== 'delivery')));
    }

    public static function advance(RentalRequest $r, string $to, array $data = []): array
    {
        if (! in_array($to, self::FLOW[$r->status] ?? [], true)) {
            return self::fail('This step is not allowed from "'.RentalRequest::statusLabel($r->status).'".');
        }
        if ($to === 'ready_pickup' && $r->fulfillment !== 'pickup') {
            return self::fail('This rental is for delivery.');
        }
        if ($to === 'out_delivery' && $r->fulfillment !== 'delivery') {
            return self::fail('This rental is for pickup.');
        }
        if (in_array($to, ['inspection', 'completed'], true)) {
            return self::fail('Use the inspection / completion action for this step.');
        }
        $log = $r->fulfillment_log ?? [];
        if ($to === 'ongoing') {
            if (empty($data['receivedBy'])) {
                return self::fail('Enter the name of the person receiving the items.');
            }
            $log = array_merge($log, ['releaseDate' => $data['releaseDate'] ?? today_iso(), 'releaseQty' => $data['releaseQty'] ?? '', 'receivedBy' => $data['receivedBy'], 'method' => $r->fulfillment]);
        }
        $r->update(['status' => $to, 'fulfillment_log' => $log ?: null]);
        self::history($r, $to, $data['note'] ?? null);
        self::changed($r);
        $msgs = [
            'in_progress' => 'is being prepared',
            'ready_pickup' => 'is ready for pickup at '.$r->supplier->address,
            'out_delivery' => 'is out for delivery',
            'ongoing' => 'has been released — enjoy your event!',
            'return_pending' => 'is due for return. Please prepare the items for pickup/return.',
        ];
        if (isset($msgs[$to])) {
            $title = $to === 'return_pending' ? 'Return reminder' : ($r->fulfillment === 'delivery' ? 'Delivery update' : 'Pickup update');
            Notifier::notify($r->customer_id, 'customer', $title, $r->code.' '.$msgs[$to].'.', self::link('rentals.show', $r));
        }

        return self::ok();
    }

    /** Peak released quantity per product across the rental dates. */
    public static function peakUnits(RentalRequest $r): array
    {
        $peak = [];
        foreach (inv()->demandMap($r->lines) as $m) {
            foreach ($m as $pid => $q) {
                $peak[$pid] = max($peak[$pid] ?? 0, $q);
            }
        }

        return $peak;
    }

    /** Return inspection: updates inventory counters by condition and records compensation. */
    public static function recordInspection(RentalRequest $r, array $data): array
    {
        if ($r->status !== 'return_pending') {
            return self::fail('Inspection can only be recorded when the rental is Return Pending.');
        }
        $peak = self::peakUnits($r);
        $items = [];
        foreach ($peak as $pid => $released) {
            $it = $data['items'][$pid] ?? [];
            $g = fn ($k) => max(0, (int) ($it[$k] ?? 0));
            $row = ['productId' => $pid, 'returned' => $g('returned'), 'good' => $g('good'), 'minor' => $g('minor'), 'major' => $g('major'), 'total' => $g('total'), 'missing' => $g('missing'), 'repairFee' => max(0, (float) ($it['repairFee'] ?? 0)), 'replaceFee' => max(0, (float) ($it['replaceFee'] ?? 0))];
            $name = inv()->product($pid)?->name ?? 'Item';
            if ($row['good'] + $row['minor'] + $row['major'] + $row['total'] !== $row['returned']) {
                return self::fail($name.': good + minor + major + completely damaged must equal returned ('.$row['returned'].').');
            }
            if ($row['returned'] + $row['missing'] !== $released) {
                return self::fail($name.': returned + missing must equal released quantity ('.$released.').');
            }
            $items[] = $row;
        }
        $comp = 0.0;
        DB::transaction(function () use ($r, $items, $data, &$comp) {
            foreach ($items as $it) {
                $p = Product::lockForUpdate()->find($it['productId']);
                if (! $p) {
                    continue;
                }
                $p->damaged += $it['major'] + $it['total'];
                $p->missing += $it['missing'];
                $p->save();
                if ($it['minor']) {
                    Hold::create(['product_id' => $p->id, 'type' => 'maintenance', 'qty' => $it['minor'], 'start_date' => today_iso(), 'end_date' => add_days(today_iso(), 3), 'note' => 'Minor damage from '.$r->code.' — for repair']);
                }
                $comp += $it['repairFee'] + $it['replaceFee'];
            }
            $summary = implode(', ', array_map(fn ($i) => $i['returned'].'× '.(inv()->product($i['productId'])?->name ?? ''), $items));
            $r->fulfillment_log = array_merge($r->fulfillment_log ?? [], ['returnDate' => $data['returnDate'] ?? today_iso(), 'returnedQty' => $summary]);
            $r->inspection = ['at' => now()->toIso8601String(), 'by' => auth()->id(), 'items' => $items, 'notes' => $data['notes'] ?? '', 'photos' => $data['photos'] ?? [], 'compensation' => r2($comp), 'settlement' => $comp > 0 ? 'pending' : 'settled'];
            $r->status = 'inspection';
            $r->save();
            self::history($r, 'inspection', $comp > 0 ? 'Damage/missing items recorded: '.money($comp) : 'All items returned in good condition');
        });
        self::changed($r);
        Notifier::notify($r->customer_id, 'customer', $comp > 0 ? 'Damage report' : 'Return inspection done', $comp > 0 ? 'The supplier recorded damaged/missing items for '.$r->code.'. Compensation: '.money($comp).'. View the report for details.' : 'All items for '.$r->code.' passed inspection.', self::link('rentals.show', $r));
        Notifier::audit('Recorded return inspection', $r->code.' · compensation '.money($comp));

        return self::ok();
    }

    /** Settle the deposit vs compensation (deducted | paid | waived) and record the deposit refund. */
    public static function settleDeposit(RentalRequest $r, string $mode): array
    {
        $f = $r->fin();
        if (! $r->inspection) {
            return self::fail('Record the return inspection first.');
        }
        if (! in_array($mode, ['deducted', 'paid', 'waived', 'settled'], true)) {
            return self::fail('Choose how to settle the deposit.');
        }
        if ($mode === 'paid' && $f['compensationDue'] > 0 && $f['compPaidDirect'] < $f['compensation']) {
            return self::fail('Compensation has not been paid yet. The customer must pay '.money($f['compensationDue']).' (verified by the cashier).');
        }
        $insp = $r->inspection;
        $insp['settlement'] = ($insp['compensation'] ?? 0) > 0 ? $mode : 'settled';
        $insp['settledAt'] = now()->toIso8601String();
        $r->update(['inspection' => $insp]);
        $r->load('payments');
        self::changed($r);
        $after = $r->fin();
        $refund = r2($after['depositHeld']);
        if ($refund > 0) {
            Payment::create([
                'code' => self::nextPaymentCode(), 'rental_request_id' => $r->id, 'type' => 'deposit_refund', 'method' => 'cash',
                'amount' => $refund, 'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'RF-'.substr((string) now()->getTimestampMs(), -5),
                'status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now(),
                'notes' => $mode === 'deducted' ? money(min($f['compensation'], $f['depositPaid'])).' deducted for damage' : 'Full deposit returned',
                'recorded_by' => 'supplier',
            ]);
            $r->load('payments');
            self::changed($r);
        }
        Notifier::notify($r->customer_id, 'customer', 'Refund update', 'Security deposit for '.$r->code.' settled. '.($refund > 0 ? money($refund).' returned to you.' : 'No refund due.').($mode === 'deducted' && $f['compensation'] ? ' '.money(min($f['compensation'], $f['depositPaid'])).' was applied to damages.' : ''), self::link('rentals.show', $r));
        Notifier::audit('Settled security deposit', $r->code.' · '.$mode.' · refund '.money($refund));

        return self::ok(['refund' => $refund]);
    }

    public static function completionChecklist(RentalRequest $r): array
    {
        $f = $r->fin();
        $insp = $r->inspection;

        return [
            ['label' => 'Returned items inspected', 'ok' => (bool) $insp, 'detail' => ''],
            ['label' => 'Rental balance fully paid', 'ok' => $f['balance'] <= 0, 'detail' => $f['balance'] > 0 ? money($f['balance']).' outstanding' : ''],
            ['label' => 'Compensation settled', 'ok' => ! $insp || (($insp['settlement'] ?? '') !== 'pending' && $f['compensationDue'] <= 0), 'detail' => $f['compensationDue'] > 0 ? money($f['compensationDue']).' due' : ''],
            ['label' => 'Security deposit resolved', 'ok' => $f['depositHeld'] <= 0, 'detail' => $f['depositHeld'] > 0 ? money($f['depositHeld']).' still held' : ''],
        ];
    }

    public static function completeRental(RentalRequest $r): array
    {
        if ($r->status !== 'inspection') {
            return self::fail('Record the return inspection before completing.');
        }
        $bad = array_filter(self::completionChecklist($r), fn ($c) => ! $c['ok']);
        if ($bad) {
            return self::fail('Cannot complete yet: '.implode('; ', array_map(fn ($b) => $b['label'].($b['detail'] ? ' ('.$b['detail'].')' : ''), $bad)));
        }
        $r->update(['status' => 'completed', 'completed_at' => today_iso()]);
        self::history($r, 'completed', 'Rental completed');
        foreach ($r->lines as $l) {
            if ($l['type'] === 'item') {
                Product::whereKey($l['ref_id'])->increment('rent_count');
            }
        }
        self::changed($r);
        Notifier::notify($r->customer_id, 'customer', 'Rental completed', $r->code.' is complete. Thank you for renting with '.$r->supplier->name.'! You can now leave a review.', self::link('rentals.show', $r));
        Notifier::audit('Completed rental', $r->code);

        return self::ok();
    }

    /* ================= PAYMENTS ================= */

    public static function nextPaymentCode(): string
    {
        return 'TXN-'.(10000 + Settings::nextSeq('payment'));
    }

    private static function issueReceipt(Payment $p): void
    {
        $p->receipt_no = 'OR-'.str_pad((string) Settings::nextSeq('receipt'), 5, '0', STR_PAD_LEFT);
    }

    public static function validatePayment(RentalRequest $r, array $data): ?string
    {
        $amt = (float) ($data['amount'] ?? 0);
        if (! ($amt > 0)) {
            return 'Enter an amount greater than zero.';
        }
        $f = $r->fin();
        if (in_array($data['type'], Finance::RENTAL_TYPES, true) && $amt > $f['balance'] - $f['pendingRental'] + 0.001) {
            return 'Amount exceeds the remaining balance ('.money(max(0, $f['balance'] - $f['pendingRental'])).' after pending submissions).';
        }
        if ($data['type'] === 'deposit' && $amt > $f['depositRequired'] - $f['depositPaid'] + 0.001) {
            return 'Deposit required is '.money(max(0, $f['depositRequired'] - $f['depositPaid'])).'.';
        }
        if ($data['type'] === 'compensation' && $amt > $f['compensationDue'] + 0.001) {
            return 'Compensation due is '.money($f['compensationDue']).'.';
        }
        if (in_array($data['method'], ['bank', 'debit'], true) && trim((string) ($data['reference'] ?? '')) === '') {
            return 'Reference number is required for bank transfers and debit payments.';
        }

        return null;
    }

    /** Customer (or supplier on the customer's behalf) submits a payment — PENDING until a cashier verifies it. */
    public static function submitPayment(RentalRequest $r, array $data, string $source = 'customer'): array
    {
        if (! in_array($r->status, Finance::ACTIVE, true) && $r->status !== 'completed') {
            return self::fail('Payments can be made once the request is accepted.');
        }
        if (! in_array($data['type'], array_merge(Finance::RENTAL_TYPES, ['deposit', 'compensation']), true)) {
            return self::fail('Choose what you are paying for.');
        }
        if ($err = self::validatePayment($r, $data)) {
            return self::fail($err);
        }
        $p = Payment::create([
            'code' => self::nextPaymentCode(), 'rental_request_id' => $r->id, 'type' => $data['type'], 'method' => $data['method'],
            'amount' => r2($data['amount']), 'date' => $data['date'] ?? today_iso(), 'submitted_at' => now(),
            'reference' => trim((string) ($data['reference'] ?? '')), 'proof_path' => $data['proof_path'] ?? null, 'proof_name' => $data['proof_name'] ?? null,
            'notes' => $data['notes'] ?? null, 'status' => 'pending', 'recorded_by' => $source,
        ]);
        $r->load('payments');
        self::changed($r);
        $cust = $r->customer;
        Notifier::notify('cashier', 'cashier', 'New payment submission', $cust->name.' · '.money($p->amount).' ('.$p->typeLabel().') for '.$r->code.' — pending verification.', self::link('cashier.verification'));
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Payment submitted', $cust->name.' submitted '.money($p->amount).' for '.$r->code.'. Awaiting cashier verification.', self::link('supplier.requests.show', $r));
        if ($source === 'supplier') {
            Notifier::notify($r->customer_id, 'customer', 'Payment recorded', 'The supplier recorded your '.money($p->amount).' payment for '.$r->code.'. It will count once verified.', self::link('rentals.show', $r));
        }

        return self::ok(['payment' => $p]);
    }

    public static function verifyPayment(Payment $p, User $by): array
    {
        if (! $by->hasPerm('payments.verify')) {
            return self::fail('You do not have permission to verify payments.');
        }

        return DB::transaction(function () use ($p, $by) {
            $p = Payment::lockForUpdate()->find($p->id);
            if (! $p || $p->status !== 'pending') {
                return self::fail('Only pending payments can be verified.');
            }
            $r = $p->rentalRequest()->with('payments')->first();
            $before = $r->fin();
            if (in_array($p->type, Finance::RENTAL_TYPES, true) && $p->amount > $before['balance'] + 0.001) {
                return self::fail('This payment exceeds the remaining balance of '.money($before['balance']).'. Reject it or ask the customer to correct the amount.');
            }
            $p->status = 'verified';
            $p->verified_by = $by->id;
            $p->verified_at = now();
            self::issueReceipt($p);
            $p->save();
            $r->load('payments');
            self::changed($r);
            $after = $r->fin();
            Notifier::notify($r->customer_id, 'customer', 'Payment verified ✅', money($p->amount).' for '.$r->code.' was verified. Receipt '.$p->receipt_no.'. Payment status: '.$after['status'].($after['balance'] > 0 ? ', balance '.money($after['balance']) : '').'.', self::link('rentals.show', $r));
            Notifier::notify($r->supplier->owner_id, 'supplier', 'Payment verified', money($p->amount).' for '.$r->code.' verified by cashier. Status: '.$after['status'].'.', self::link('supplier.requests.show', $r));
            Notifier::audit('Verified payment', $p->code.' · '.money($p->amount).' · '.$r->code.' · '.$p->receipt_no, $by);

            return self::ok(['payment' => $p]);
        });
    }

    public static function rejectPayment(Payment $p, string $reason, User $by): array
    {
        if (! $by->hasPerm('payments.verify')) {
            return self::fail('You do not have permission to reject payments.');
        }
        if ($p->status !== 'pending') {
            return self::fail('Only pending payments can be rejected.');
        }
        if (trim($reason) === '') {
            return self::fail('Please give a reason so the customer can correct it.');
        }
        $p->update(['status' => 'rejected', 'reject_reason' => $reason, 'verified_by' => $by->id, 'verified_at' => now()]);
        $r = $p->rentalRequest;
        self::changed($r);
        Notifier::notify($r->customer_id, 'customer', 'Payment rejected', money($p->amount).' for '.$r->code.' was rejected: '.$reason, self::link('rentals.show', $r));
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Payment rejected', 'A '.money($p->amount).' submission for '.$r->code.' was rejected by the cashier.', self::link('supplier.requests.show', $r));
        Notifier::audit('Rejected payment', $p->code.' · '.money($p->amount).' · '.$reason, $by);

        return self::ok();
    }

    /** Cashier records an authorized offline payment or refund — verified at once, receipt issued. */
    public static function cashierRecord(RentalRequest $r, array $data, User $by): array
    {
        if (! $by->hasPerm('payments.record')) {
            return self::fail('You do not have permission to record payments.');
        }
        $f = $r->fin();
        if (! in_array($data['type'], array_merge(Finance::RENTAL_TYPES, ['deposit', 'compensation', 'refund']), true)) {
            return self::fail('Choose a payment type.');
        }
        if ($data['type'] === 'refund' && (float) $data['amount'] > $f['paidRental'] - $f['refunded'] + 0.001) {
            return self::fail('Refund cannot exceed verified rental payments ('.money($f['paidRental'] - $f['refunded']).').');
        }
        if ($data['type'] !== 'refund' && ($err = self::validatePayment($r, $data))) {
            return self::fail($err);
        }
        if (trim((string) ($data['reference'] ?? '')) === '') {
            return self::fail('Enter the official reference / OR number.');
        }
        $p = new Payment([
            'code' => self::nextPaymentCode(), 'rental_request_id' => $r->id, 'type' => $data['type'], 'method' => $data['method'],
            'amount' => r2($data['amount']), 'date' => $data['date'] ?? today_iso(), 'submitted_at' => now(), 'reference' => trim($data['reference']),
            'notes' => $data['notes'] ?? null, 'status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now(), 'recorded_by' => 'cashier',
        ]);
        self::issueReceipt($p);
        $p->save();
        $r->load('payments');
        self::changed($r);
        $after = $r->fin();
        $isRefund = $data['type'] === 'refund';
        Notifier::notify($r->customer_id, 'customer', $isRefund ? 'Refund update' : 'Payment verified ✅', money($p->amount).' '.($isRefund ? 'refunded' : 'received').' for '.$r->code.'. Receipt '.$p->receipt_no.'.', self::link('rentals.show', $r));
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Payment verified', 'Cashier recorded '.money($p->amount).' ('.$p->typeLabel().') for '.$r->code.'. Status: '.$after['status'].'.', self::link('supplier.requests.show', $r));
        Notifier::audit('Recorded offline '.($isRefund ? 'refund' : 'payment'), $p->code.' · '.money($p->amount).' · '.$r->code, $by);

        return self::ok(['payment' => $p]);
    }

    /** Flag newly overdue schedule items and notify everyone involved (runs on a schedule and on dashboard loads). */
    public static function scanOverdue(): int
    {
        $count = 0;
        foreach (RentalRequest::whereIn('status', Finance::ACTIVE)->with(['payments', 'supplier', 'customer'])->get() as $r) {
            $f = $r->fin();
            $flags = $r->overdue_flags ?? [];
            foreach ($f['schedule'] as $s) {
                if ($s['state'] !== 'overdue' || isset($flags[$s['key']])) {
                    continue;
                }
                $flags[$s['key']] = now()->toIso8601String();
                $count++;
                $msg = $s['label'].' of '.money($s['amount']).' for '.$r->code.' was due '.fmt_date($s['due']).'. Outstanding: '.money($f['overdueAmount']).'.';
                Notifier::notify($r->customer_id, 'customer', 'Payment overdue', $msg.' Please pay as soon as possible.', self::link('rentals.show', $r));
                Notifier::notify($r->supplier->owner_id, 'supplier', 'Overdue payment', $r->customer->name.': '.$msg, self::link('supplier.requests.show', $r));
                Notifier::notify('cashier', 'cashier', 'Overdue payment', $r->customer->name.': '.$msg, self::link('cashier.dashboard'));
                Notifier::notify('admin', 'admin', 'Overdue payment', $r->customer->name.' · '.$msg, '');
                Notifier::audit('Payment marked overdue', $r->code.' · '.$s['label'].' · '.money($f['overdueAmount']), null);
            }
            if ($flags !== ($r->overdue_flags ?? [])) {
                $r->overdue_flags = $flags;
                $r->saveQuietly();
            }
        }

        return $count;
    }

    /* ================= SUPPLIER ONBOARDING ================= */

    public static function submitApplication(User $u, array $data): array
    {
        $existing = $u->supplier;
        if ($existing && $existing->status !== 'rejected') {
            return self::fail('You already have a supplier profile ('.$existing->status.').');
        }
        $needsReview = (bool) Settings::get('requireSupplierApproval', true);
        $attrs = array_merge($data, [
            'owner_id' => $u->id, 'status' => $needsReview ? 'pending' : 'approved', 'applied_at' => now(),
            'approved_at' => $needsReview ? null : now(), 'reject_reason' => null, 'reviewed_at' => null, 'reviewed_by' => null,
        ]);
        $s = $existing ? tap($existing)->update($attrs) : Supplier::create(array_merge(['rating' => 0], $attrs));
        if ($needsReview) {
            Notifier::notify('admin', 'admin', 'New supplier application', $u->name.' applied to open '.$s->name.'.', '');
            Notifier::notify($u, 'customer', 'Application submitted', 'Your supplier application for '.$s->name.' is pending review. We will notify you once it is approved.', self::link('start-selling'));
        } else {
            Notifier::notify($u, 'customer', 'Your shop is live!', $s->name.' is approved. Switch to Supplier Mode to add your first product.', self::link('supplier.dashboard'));
        }
        Notifier::audit('Submitted supplier application', $s->name, $u);

        return self::ok(['supplier' => $s]);
    }

    public static function reviewApplication(Supplier $s, bool $approve, ?string $reason, User $by): array
    {
        if (! $by->hasPerm('applications.review')) {
            return self::fail('You do not have permission to review applications.');
        }
        if ($s->status !== 'pending') {
            return self::fail('This application is not pending.');
        }
        if (! $approve && ! trim((string) $reason)) {
            return self::fail('Please state the reason for rejection.');
        }
        $s->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_at' => now(), 'reviewed_by' => $by->id, 'reject_reason' => $approve ? null : $reason, 'approved_at' => $approve ? now() : null]);
        Notifier::notify($s->owner_id, 'customer', $approve ? 'Supplier application approved 🎉' : 'Supplier application rejected', $approve ? $s->name.' is approved! Use "Switch to Supplier Mode" in your profile menu to start listing items.' : 'Your application for '.$s->name.' was not approved: '.$reason.'. You may update and resubmit.', self::link('start-selling'));
        Notifier::audit($approve ? 'Approved supplier application' : 'Rejected supplier application', $s->name.($reason ? ' — '.$reason : ''), $by);

        return self::ok();
    }

    /* ================= SUPPLIER CATALOG / INVENTORY ================= */

    /** Validate and normalize pricing tiers: continuous, starting at 1, last tier open-ended. */
    public static function normalizeTiers(array $tiers): array|string
    {
        $tiers = array_values(array_filter(array_map(fn ($t) => ['min' => (int) ($t['min'] ?? 0), 'max' => isset($t['max']) && $t['max'] !== '' && $t['max'] !== null ? (int) $t['max'] : null, 'price' => r2($t['price'] ?? 0)], $tiers), fn ($t) => $t['price'] > 0));
        usort($tiers, fn ($a, $b) => $a['min'] <=> $b['min']);
        if (! $tiers) {
            return 'Add at least one price.';
        }
        foreach ($tiers as $i => $t) {
            if ($i === 0 && $t['min'] !== 1) {
                return 'The first pricing tier must start at quantity 1.';
            }
            if ($i > 0 && $t['min'] !== ($tiers[$i - 1]['max'] ?? 0) + 1) {
                return 'Pricing tiers must be continuous (tier '.($i + 1).' should start at '.(($tiers[$i - 1]['max'] ?? 0) + 1).').';
            }
            if ($i < count($tiers) - 1 && ! (($t['max'] ?? 0) >= $t['min'])) {
                return 'Tier '.($i + 1).' needs a maximum quantity.';
            }
        }
        $tiers[count($tiers) - 1]['max'] = null;

        return $tiers;
    }

    public static function saveProduct(Supplier $s, array $data, ?Product $p = null): array
    {
        $tiers = self::normalizeTiers($data['tiers'] ?? []);
        if (is_string($tiers)) {
            return self::fail($tiers);
        }
        $data['tiers'] = $tiers;
        if ($p) {
            if ($p->supplier_id !== $s->id) {
                return self::fail('You can only edit your own products.');
            }
            $conflicts = inv()->stockChangeConflicts($p->id, (int) $data['total_stock']);
            if ($conflicts) {
                return self::fail('Total stock cannot be reduced to '.$data['total_stock'].'. Accepted reservations need '.implode(', ', array_map(fn ($c) => $c['reserved'].' on '.fmt_date($c['date']), $conflicts)).'.');
            }
            $priceChanged = json_encode($p->tiers) !== json_encode($tiers);
            $p->update($data);
            inv()->flush();
            if ($priceChanged) {
                Notifier::audit('Updated product pricing', $p->name.' · '.implode(', ', array_map(fn ($t) => $t['min'].($t['max'] ? '–'.$t['max'] : '+').': '.money($t['price']), $tiers)));
            }

            return self::ok(['product' => $p]);
        }
        $p = Product::create(array_merge(['damaged' => 0, 'missing' => 0, 'rating' => 0, 'rent_count' => 0, 'images' => []], $data, ['supplier_id' => $s->id]));
        Notifier::audit('Created product', $p->name);

        return self::ok(['product' => $p]);
    }

    public static function addHold(Product $p, array $h): array
    {
        if (! ((int) ($h['qty'] ?? 0) > 0)) {
            return self::fail('Quantity must be at least 1.');
        }
        if (! empty($h['end_date']) && $h['end_date'] < $h['start_date']) {
            return self::fail('End date must be on or after the start date.');
        }
        $c = inv()->holdConflicts($p->id, (int) $h['qty'], $h['start_date'], $h['end_date'] ?: null);
        if ($c) {
            return self::fail('Setting aside '.$h['qty'].' units would break accepted reservations on '.implode(', ', array_map(fn ($x) => fmt_date($x['date']).' ('.$x['reserved'].' reserved)', $c)).'.');
        }
        Hold::create(['product_id' => $p->id, 'type' => $h['type'] === 'setaside' ? 'setaside' : 'maintenance', 'qty' => (int) $h['qty'], 'start_date' => $h['start_date'], 'end_date' => $h['end_date'] ?: null, 'note' => $h['note'] ?? '']);
        inv()->flush();

        return self::ok();
    }

    /** Adjust damaged / missing counters (repaired or found units go back to available). */
    public static function adjustCondition(Product $p, string $field, int $newValue): array
    {
        if (! in_array($field, ['damaged', 'missing'], true)) {
            return self::fail('Unknown field.');
        }
        if ($newValue < 0) {
            return self::fail('Value cannot be negative.');
        }
        $c = inv()->stockChangeConflicts($p->id, $p->total_stock, $field === 'damaged' ? $newValue : null, $field === 'missing' ? $newValue : null);
        if ($c) {
            return self::fail('This would leave too few units for accepted reservations on '.implode(', ', array_map(fn ($x) => fmt_date($x['date']), $c)).'.');
        }
        $p->update([$field => $newValue]);
        inv()->flush();
        Notifier::audit('Adjusted '.$field.' units', $p->name.' → '.$newValue);

        return self::ok();
    }

    public static function savePackage(Supplier $s, array $data, ?Package $k = null): array
    {
        if (empty($data['name'])) {
            return self::fail('Package name is required.');
        }
        $items = array_values(array_filter($data['items'] ?? [], fn ($it) => ! empty($it['product_id'])));
        if (! $items) {
            return self::fail('Add at least one item to the package.');
        }
        $own = Product::whereIn('id', array_column($items, 'product_id'))->where('supplier_id', $s->id)->pluck('id')->all();
        foreach ($items as $it) {
            if (! in_array((int) $it['product_id'], $own, true)) {
                return self::fail('Packages can only contain your own items.');
            }
            if (! ((int) $it['qty'] > 0)) {
                return self::fail('Every package item needs a quantity of at least 1.');
            }
        }
        if (! ((float) ($data['price'] ?? 0) > 0)) {
            return self::fail('Set a package price.');
        }
        $data['items'] = array_map(fn ($it) => ['product_id' => (int) $it['product_id'], 'qty' => (int) $it['qty']], $items);
        if ($k) {
            if ($k->supplier_id !== $s->id) {
                return self::fail('Not your package.');
            }
            $k->update($data);
        } else {
            $k = Package::create(array_merge(['active' => true, 'images' => []], $data, ['supplier_id' => $s->id]));
        }
        inv()->flush();

        return self::ok(['package' => $k]);
    }
}
