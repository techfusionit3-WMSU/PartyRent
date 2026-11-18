<?php

namespace App\Domain;

use App\Models\CaseEvent;
use App\Models\Hold;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\RequestEvent;
use App\Models\ShortfallCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shortfall / dispute flow (change list C12).
 *
 * 1. report(): the supplier reports units of an item they cannot provide on some dates (damaged, lost…). The units
 *    are taken out of stock with a maintenance hold, and every accepted booking that no longer fits is flagged:
 *    per date, overbooked = reserved − usable; the shortage goes to the most recently accepted bookings first.
 *    One case per affected booking; the customer is notified at once.
 * 2. propose(): the supplier (or the customer, as a counter) proposes: partial (fewer units, price follows),
 *    substitute (another item for the missing units), delay (late / split delivery), refund (money back) or
 *    cancel (whole booking, full refund) — each with an optional credit and a deadline.
 * 3. respond(): the other side accepts, counters or declines. Accepting applies it at once.
 * 4. escalateDue(): an open proposal past its deadline goes to PartyRent support (admins), who can apply any
 *    proposal of the case or close it.
 * 5. apply(): booking lines, booking_days (inventory + calendar), price snapshot (credits) and amounts owed are
 *    updated; money paid above the new total becomes a pending refund for the shop's cashier. Every step is a
 *    case event (timeline + thread + audit log) and goes to the audit trail.
 */
class Shortfalls
{
    public const TYPES = [
        'partial' => 'Partial fulfillment (fewer units, lower price)',
        'substitute' => 'Substitute item',
        'delay' => 'Delayed or partial delivery',
        'refund' => 'Partial or full refund',
        'cancel' => 'Cancel the booking (full refund)',
        'compensation' => 'Compensation only (no change to the items)',
    ];

    /**
     * Who pays a compensation. A case is always about the shop's side (units it can't provide, or items the customer
     * reports missing, damaged, wrong or late), so the customer never pays anything extra: only the shop compensates.
     * (Damage the customer causes is charged through the return inspection, not through a case.)
     */
    public const COMP_PAYERS = ['supplier'];

    /** Problems a customer can report about the items of their booking. */
    public const PROBLEMS = [
        'missing' => 'Missing items / fewer units than booked',
        'damaged' => 'Damaged or not working on arrival',
        'wrong' => 'Wrong item delivered',
        'late' => 'Late or no delivery',
        'other' => 'Other problem with the items',
    ];

    /** Booking stages in which the customer can report a problem with the items. */
    public const REPORTABLE = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing', 'return_pending'];

    public const DEFAULT_DEADLINE_HOURS = 48;

    private static function fail(string $e): array
    {
        return ['ok' => false, 'error' => $e];
    }

    private static function ok(array $x = []): array
    {
        return ['ok' => true, 'error' => null] + $x;
    }

    private static function link(ShortfallCase $c): string
    {
        return route('cases.show', $c, false);
    }

    public static function event(ShortfallCase $c, string $role, string $kind, ?string $body = null, ?array $data = null, ?User $by = null): CaseEvent
    {
        return CaseEvent::create(['shortfall_case_id' => $c->id, 'user_id' => $by?->id ?? auth()->id(), 'role' => $role, 'kind' => $kind, 'body' => $body, 'data' => $data, 'created_at' => now()]);
    }

    /** Units of $pid each accepted booking would lose on $dates if $qty units disappear. [requestId => [date => units]] */
    public static function affected(Product $p, array $dates, int $qty, bool $holdAlreadyPlaced = false): array
    {
        $out = [];
        foreach ($dates as $d) {
            $b = inv()->breakdown($p->id, $d);
            $usable = $b['total'] - inv()->unusableOn($p, $d) - ($holdAlreadyPlaced ? 0 : $qty);
            $over = $b['reserved'] - max(0, $usable);
            if ($over <= 0) {
                continue;
            }
            $refs = collect($b['reservedRefs'])->map(fn ($r) => $r + ['accepted_at' => (string) RentalRequest::whereKey($r['request_id'])->value('accepted_at')])
                ->sortByDesc(fn ($r) => $r['accepted_at'].'|'.str_pad((string) $r['request_id'], 10, '0', STR_PAD_LEFT));
            foreach ($refs as $r) {
                if ($over <= 0) {
                    break;
                }
                $take = min($over, (int) $r['qty']);
                $out[$r['request_id']][$d] = $take;
                $over -= $take;
            }
        }

        return $out;
    }

    /**
     * Supplier reports a shortfall. $data: product_id, qty, dates [Y-m-d…], reason, photo (stored path|null).
     * Returns ['ok', 'cases' => [...], 'report' => code].
     */
    public static function report(User $by, \App\Models\Supplier $s, array $data): array
    {
        $p = Product::where('supplier_id', $s->id)->find($data['product_id'] ?? 0);
        $qty = (int) ($data['qty'] ?? 0);
        $dates = array_values(array_unique(array_filter((array) ($data['dates'] ?? []))));
        sort($dates);
        if (! $p || $p->isSale()) {
            return self::fail('Choose one of your rental items.');
        }
        if ($qty < 1 || $qty > $p->total_stock) {
            return self::fail('The quantity must be between 1 and '.$p->total_stock.'.');
        }
        if (! $dates || $dates[0] < today_iso()) {
            return self::fail('Choose the affected dates (today or later).');
        }
        if (trim((string) ($data['reason'] ?? '')) === '') {
            return self::fail('Say what happened.');
        }

        return DB::transaction(function () use ($by, $s, $p, $qty, $dates, $data) {
            $hit = self::affected($p, $dates, $qty);
            // the units are out of stock on those dates (no new bookings), even if this breaks accepted ones
            $hold = Hold::create(['product_id' => $p->id, 'type' => 'maintenance', 'qty' => $qty, 'start_date' => $dates[0], 'end_date' => end($dates),
                'note' => 'Shortfall: '.mb_strimwidth(trim($data['reason']), 0, 200, '…')]);
            inv()->flush();
            $report = 'SR-'.str_pad((string) (ShortfallCase::distinct()->count('report') + 1), 4, '0', STR_PAD_LEFT);
            $cases = [];
            foreach ($hit as $rid => $short) {
                $r = RentalRequest::find($rid);
                $c = ShortfallCase::create([
                    'code' => 'SC-'.str_pad((string) (ShortfallCase::max('id') + 1), 4, '0', STR_PAD_LEFT), 'report' => $report,
                    'supplier_id' => $s->id, 'rental_request_id' => $r->id, 'customer_id' => $r->customer_id, 'product_id' => $p->id, 'hold_id' => $hold->id,
                    'short' => $short, 'reason' => trim($data['reason']), 'photo' => $data['photo'] ?? null, 'status' => 'reported',
                ]);
                $units = array_sum($short);
                self::event($c, 'supplier', 'report', trim($data['reason']), ['product' => $p->name, 'short' => $short, 'photo' => $c->photo], $by);
                RequestEvent::create(['rental_request_id' => $r->id, 'status' => 'shortfall', 'user_id' => $by->id, 'note' => $c->code.': '.$units.' × '.$p->name.' short', 'created_at' => now()]);
                Notifier::notify($r->customer_id, 'customer', 'Problem with your booking '.$r->code,
                    $s->name.' cannot provide '.$units.' × '.$p->name.' on '.implode(', ', array_map('fmt_date', array_keys($short))).'. Reason: '.trim($data['reason']).' They will propose a solution — you can accept, counter or decline.', self::link($c));
                Notifier::audit('Reported shortfall', $c->code.' · '.$r->code.' · '.$units.' × '.$p->name, $by);
                $cases[] = $c;
            }
            if (! $cases) {
                Notifier::audit('Reported shortfall', $report.' · '.$qty.' × '.$p->name.' · spare stock covers every booking', $by);
            }

            return self::ok(['cases' => $cases, 'report' => $report, 'hold' => $hold]);
        });
    }

    /** Rental units of every product in a booking per date (packages expanded): [pid => [date => units]]. */
    public static function bookedUnits(RentalRequest $r): array
    {
        $out = [];
        foreach (inv()->demandMap($r->lines ?? []) as $date => $units) {
            foreach ($units as $pid => $q) {
                if ($q > 0) {
                    $out[$pid][$date] = $q;
                }
            }
        }

        return $out;
    }

    /**
     * The customer reports a problem with items of their booking (missing, damaged, wrong, late…). Opens a case
     * like a shortfall: the shop must propose a solution within the default deadline, otherwise PartyRent support
     * steps in. Nothing is charged to the customer through a case.
     * $data: product_id, problem, qty, dates[], details, photo
     */
    public static function reportByCustomer(User $by, RentalRequest $r, array $data): array
    {
        if ($r->customer_id !== $by->id) {
            return self::fail('You can only report problems on your own bookings.');
        }
        if (! in_array($r->status, self::REPORTABLE, true)) {
            return self::fail($r->status === 'pending'
                ? 'The shop has not accepted '.$r->code.' yet. Message them instead, or cancel the request.'
                : 'Problems can be reported from acceptance until the items are returned. For anything else, message the shop or contact PartyRent support.');
        }
        $booked = self::bookedUnits($r);
        $pid = (int) ($data['product_id'] ?? 0);
        $p = isset($booked[$pid]) ? Product::find($pid) : null;
        if (! $p) {
            return self::fail('Choose the item that has a problem.');
        }
        if (! isset(self::PROBLEMS[$data['problem'] ?? ''])) {
            return self::fail('Choose what went wrong.');
        }
        $qty = (int) ($data['qty'] ?? 0);
        $max = max($booked[$pid]);
        if ($qty < 1 || $qty > $max) {
            return self::fail('Enter how many '.$p->name.' are affected (1 to '.$max.').');
        }
        $dates = array_values(array_intersect(array_keys($booked[$pid]), (array) ($data['dates'] ?? [])));
        if (! $dates) {
            $dates = array_keys($booked[$pid]);
        }
        sort($dates);
        $details = trim((string) ($data['details'] ?? ''));
        if (mb_strlen($details) < 5) {
            return self::fail('Describe the problem in a few words so the shop can fix it.');
        }
        $dup = ShortfallCase::where('rental_request_id', $r->id)->where('product_id', $p->id)->where('status', '!=', 'resolved')->first();
        if ($dup) {
            return self::fail('There is already an open case ('.$dup->code.') for '.$p->name.' on this booking. Add to it there.');
        }
        $short = [];
        foreach ($dates as $d) {
            $short[$d] = min($qty, (int) $booked[$pid][$d]);
        }

        return DB::transaction(function () use ($by, $r, $p, $short, $details, $data) {
            $label = self::PROBLEMS[$data['problem']];
            $c = ShortfallCase::create([
                'code' => 'SC-'.str_pad((string) (ShortfallCase::max('id') + 1), 4, '0', STR_PAD_LEFT),
                'report' => 'CR-'.str_pad((string) (ShortfallCase::where('kind', 'problem')->count() + 1), 4, '0', STR_PAD_LEFT),
                'kind' => 'problem', 'problem' => $data['problem'],
                'supplier_id' => $r->supplier_id, 'rental_request_id' => $r->id, 'customer_id' => $r->customer_id, 'product_id' => $p->id,
                'short' => $short, 'reason' => $label.': '.$details, 'photo' => $data['photo'] ?? null, 'status' => 'reported',
                'deadline_at' => now()->addHours(self::DEFAULT_DEADLINE_HOURS), // the shop answers first; if not, support steps in
            ]);
            $units = array_sum($short);
            self::event($c, 'customer', 'report', $details, ['product' => $p->name, 'short' => $short, 'photo' => $c->photo, 'problem' => $data['problem']], $by);
            RequestEvent::create(['rental_request_id' => $r->id, 'status' => 'shortfall', 'user_id' => $by->id, 'note' => $c->code.': customer reported '.mb_strtolower($label).' — '.$units.' × '.$p->name, 'created_at' => now()]);
            Notifier::notify($r->supplier->owner_id, 'supplier', 'Problem reported on '.$r->code,
                $by->name.' reported: '.$label.' — '.$units.' × '.$p->name.'. "'.mb_strimwidth($details, 0, 160, '…').'" Propose a solution within '.self::DEFAULT_DEADLINE_HOURS.' hours or PartyRent support steps in.', self::link($c));
            Notifier::audit('Customer reported a problem', $c->code.' · '.$r->code.' · '.$label.' · '.$units.' × '.$p->name, $by);

            return self::ok(['case' => $c]);
        });
    }

    /** Price of the missing units at the booking's quoted rates (default credit for a partial / substitute). */
    public static function shortValue(ShortfallCase $c): float
    {
        $r = $c->rentalRequest;
        $sum = 0.0;
        foreach ($r->pricing['lines'] ?? [] as $l) {
            if (($l['type'] ?? '') !== 'item' || (int) $l['refId'] !== $c->product_id) {
                continue;
            }
            foreach ($l['days'] as $d) {
                $sum += (float) $d['unit'] * min((int) ($c->short[$d['date']] ?? 0), (int) $d['qty']);
            }
        }

        return r2($sum);
    }

    /**
     * Make a proposal. $p: type, credit (₱ off the booking), note, substitute_id, deadline_hours.
     * $role supplier | customer (counter). Replaces the open proposal.
     */
    public static function propose(ShortfallCase $c, User $by, string $role, array $p): array
    {
        if (! $c->isOpen() || $c->status === 'agreed') {
            return self::fail('This case is closed.');
        }
        $type = $p['type'] ?? '';
        if (! isset(self::TYPES[$type])) {
            return self::fail('Choose what you propose.');
        }
        if ($role === 'customer' && $type === 'substitute') {
            return self::fail('Ask for a substitute in the message instead.');
        }
        // steps can't be skipped: the shop makes the first proposal; the customer then answers or counters it
        if ($role === 'customer' && ! CaseEvent::where('shortfall_case_id', $c->id)->where('kind', 'proposal')->exists()) {
            return self::fail('Wait for the shop\'s first proposal, then accept, decline or counter it.');
        }
        $r = $c->rentalRequest;
        $credit = r2(max(0, (float) ($p['credit'] ?? 0)));
        $total = (float) ($r->pricing['total'] ?? 0);
        if ($type === 'refund' && $credit <= 0) {
            return self::fail('Enter the refund amount.');
        }
        if ($credit > $total + 0.001) {
            return self::fail('The amount cannot be more than the booking total ('.money($total).').');
        }
        $data = ['type' => $type, 'credit' => $credit, 'by' => $role, 'status' => 'open'];
        // compensation for rush costs, either way
        $comp = r2(max(0, (float) ($p['comp_amount'] ?? 0)));
        if ($comp > 0) {
            if (($p['comp_payer'] ?? 'supplier') === 'customer') {
                return self::fail('This is the shop\'s side of the booking, so the customer doesn\'t pay anything extra. Only the shop can compensate.');
            }
            $payer = 'supplier';
            if ($comp > max($total, 1000) * 2) {
                return self::fail('That compensation is far above the booking total. Explain it in the message or ask PartyRent support.');
            }
            if ($payer === 'customer' && $type === 'cancel') {
                return self::fail('A cancelled booking cannot charge the customer rush costs.');
            }
            $reason = trim((string) ($p['comp_reason'] ?? ''));
            if (mb_strlen($reason) < 3) {
                return self::fail('Say what the compensation is for (e.g. rush fee for a replacement).');
            }
            $data['comp'] = ['amount' => $comp, 'payer' => $payer, 'reason' => mb_substr($reason, 0, 200)];
        } elseif ($type === 'compensation') {
            return self::fail('Enter the compensation amount.');
        }
        if ($type === 'compensation') {
            $data['credit'] = 0.0;
        }
        if ($type === 'substitute') {
            $sub = Product::where('supplier_id', $c->supplier_id)->where('status', 'active')->find($p['substitute_id'] ?? 0);
            if (! $sub || $sub->isSale() || $sub->id === $c->product_id) {
                return self::fail('Choose the substitute item.');
            }
            $line = ['type' => 'item', 'ref_id' => $sub->id, 'days' => $c->short];
            $chk = inv()->check([$line], $r->id);
            if (! $chk['ok']) {
                return self::fail($sub->name.' is not available in that quantity on those dates.');
            }
            $data += ['substituteId' => $sub->id, 'substitute' => $sub->name];
        }
        if ($type === 'cancel') {
            $data['credit'] = 0.0; // the whole paid amount comes back
        }
        $hours = max(1, min(168, (int) ($p['deadline_hours'] ?? self::DEFAULT_DEADLINE_HOURS)));
        $deadline = now()->addHours($hours);
        $data['deadline'] = $deadline->toIso8601String();
        $note = trim((string) ($p['note'] ?? ''));
        if ($old = $c->proposal()) {
            $old->update(['data' => array_merge($old->data, ['status' => 'replaced'])]);
        }
        $e = self::event($c, $role, $role === 'customer' ? 'counter' : 'proposal', $note ?: null, $data, $by);
        $c->update(['proposal_id' => $e->id, 'deadline_at' => $deadline, 'status' => $role === 'customer' ? 'countered' : 'proposed']);
        $txt = self::describe($data);
        if ($role === 'customer') {
            Notifier::notify($c->supplier->owner_id, 'supplier', 'Counter-offer on '.$c->code, $c->customer->name.' proposes: '.$txt.'. Reply before '.$deadline->format('M j, g:i A').'.', self::link($c));
        } else {
            Notifier::notify($c->customer_id, 'customer', 'Proposed solution for '.$r->code, $c->supplier->name.' proposes: '.$txt.'. Accept, counter or decline before '.$deadline->format('M j, g:i A').'.', self::link($c));
        }
        Notifier::audit($role === 'customer' ? 'Countered shortfall proposal' : 'Proposed shortfall resolution', $c->code.' · '.$txt, $by);

        return self::ok(['event' => $e]);
    }

    public static function describe(array $d): string
    {
        $t = match ($d['type']) {
            'partial' => 'deliver fewer units',
            'substitute' => 'substitute with '.($d['substitute'] ?? 'another item'),
            'delay' => 'delayed / partial delivery',
            'refund' => 'refund',
            'cancel' => 'cancel the booking with a full refund',
            'compensation' => 'compensation',
            default => $d['type'],
        };
        $comp = $d['comp'] ?? null;

        return $t.(($d['credit'] ?? 0) > 0 ? ' · '.money($d['credit']).' off' : '')
            .($comp ? ' · '.($comp['payer'] === 'supplier' ? 'shop pays the customer ' : 'customer pays the shop ').money($comp['amount']).' ('.$comp['reason'].')' : '');
    }

    /** The other side answers the open proposal: accept | decline. (A counter is a propose() by the customer.) */
    public static function respond(ShortfallCase $c, User $by, string $role, string $answer, string $note = ''): array
    {
        $e = $c->proposal();
        if (! $e || ($e->data['status'] ?? '') !== 'open') {
            return self::fail('There is no open proposal to answer.');
        }
        if (($e->data['by'] ?? '') === $role) {
            return self::fail('Wait for the other side to answer your proposal.');
        }
        if ($answer === 'decline') {
            $e->update(['data' => array_merge($e->data, ['status' => 'declined'])]);
            self::event($c, $role, 'decline', trim($note) ?: null, ['proposal' => $e->id], $by);
            $c->update(['status' => $role === 'customer' ? 'countered' : 'reported', 'proposal_id' => null, 'deadline_at' => now()->addHours(self::DEFAULT_DEADLINE_HOURS)]);
            $to = $role === 'customer' ? [$c->supplier->owner_id, 'supplier'] : [$c->customer_id, 'customer'];
            Notifier::notify($to[0], $to[1], 'Proposal declined on '.$c->code, $by->name.' declined "'.self::describe($e->data).'".'.($note ? ' "'.$note.'"' : '').' Send a new proposal within '.self::DEFAULT_DEADLINE_HOURS.' hours or PartyRent support steps in.', self::link($c));
            Notifier::audit('Declined shortfall proposal', $c->code, $by);

            return self::ok();
        }
        if ($answer !== 'accept') {
            return self::fail('Unknown answer.');
        }
        $e->update(['data' => array_merge($e->data, ['status' => 'accepted'])]);
        self::event($c, $role, 'accept', trim($note) ?: null, ['proposal' => $e->id], $by);
        $c->update(['status' => 'agreed']);

        return self::apply($c->fresh(), $e->fresh(), $by, $role);
    }

    public static function message(ShortfallCase $c, User $by, string $role, string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return self::fail('Write a message.');
        }
        self::event($c, $role, 'message', mb_substr($body, 0, 2000), null, $by);
        if ($role !== 'customer') {
            Notifier::notify($c->customer_id, 'customer', 'New message on '.$c->code, mb_strimwidth($body, 0, 140, '…'), self::link($c));
        }
        if ($role !== 'supplier') {
            Notifier::notify($c->supplier->owner_id, 'supplier', 'New message on '.$c->code, mb_strimwidth($body, 0, 140, '…'), self::link($c));
        }

        return self::ok();
    }

    /** Open proposals (or unanswered reports / declines) past their deadline go to PartyRent support. */
    public static function escalateDue(): int
    {
        $n = 0;
        foreach (ShortfallCase::whereIn('status', ['reported', 'proposed', 'countered'])->whereNotNull('deadline_at')->where('deadline_at', '<', now())->get() as $c) {
            if ($e = $c->proposal()) {
                $e->update(['data' => array_merge($e->data, ['status' => 'expired'])]);
            }
            $c->update(['status' => 'escalated', 'proposal_id' => null, 'deadline_at' => null]);
            self::event($c, 'system', 'escalate', 'No agreement before the deadline. PartyRent support will help settle this case.');
            Notifier::notify('admin', 'admin', 'Shortfall case escalated: '.$c->code, $c->supplier->name.' and '.$c->customer->name.' did not agree in time ('.$c->rentalRequest->code.').', self::link($c));
            Notifier::notify($c->customer_id, 'customer', 'PartyRent support is on '.$c->code, 'No agreement was reached in time, so PartyRent support will help settle it.', self::link($c));
            Notifier::notify($c->supplier->owner_id, 'supplier', 'PartyRent support is on '.$c->code, 'No agreement was reached in time, so PartyRent support will help settle it.', self::link($c));
            Notifier::audit('Escalated shortfall case', $c->code, null);
            $n++;
        }

        return $n;
    }

    /** Support settles an escalated case with one of its proposals ($eventId) or by closing it without changes. */
    public static function settle(ShortfallCase $c, User $admin, ?int $eventId, string $note): array
    {
        if ($c->status === 'resolved') {
            return self::fail('This case is already resolved.');
        }
        if (! $eventId) {
            self::event($c, 'admin', 'resolve', trim($note) ?: 'Closed by PartyRent support without changes.', null, $admin);
            $c->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution' => ['type' => 'none', 'by' => 'admin'], 'proposal_id' => null, 'deadline_at' => null]);
            Notifier::audit('Closed shortfall case', $c->code, $admin);

            return self::ok();
        }
        $e = CaseEvent::where('shortfall_case_id', $c->id)->whereIn('kind', ['proposal', 'counter'])->find($eventId);
        if (! $e) {
            return self::fail('Choose one of the proposals of this case.');
        }
        if (trim($note) !== '') {
            self::event($c, 'admin', 'message', trim($note), null, $admin);
        }

        return self::apply($c, $e, $admin, 'admin');
    }

    /** Apply an agreed proposal to the booking (lines, inventory, calendar, price, amounts owed, refund). */
    public static function apply(ShortfallCase $c, CaseEvent $e, User $by, string $role): array
    {
        $d = $e->data;
        $r = $c->rentalRequest;

        return DB::transaction(function () use ($c, $e, $d, $r, $by, $role) {
            $before = ['total' => (float) $r->pricing['total'], 'lines' => $r->lines];
            $paidBefore = $r->fin();
            $lines = $r->lines;
            $changes = [];
            if (in_array($d['type'], ['partial', 'substitute'], true)) {
                $removed = [];
                foreach ($lines as $i => $l) {
                    if (($l['type'] ?? '') !== 'item' || (int) $l['ref_id'] !== $c->product_id) {
                        continue;
                    }
                    foreach ($c->short as $date => $n) {
                        $left = (int) $n - ($removed[$date] ?? 0);
                        if ($left <= 0 || ! isset($l['days'][$date])) {
                            continue;
                        }
                        $cut = min($left, (int) $l['days'][$date]);
                        $lines[$i]['days'][$date] = (int) $l['days'][$date] - $cut;
                        if ($lines[$i]['days'][$date] <= 0) {
                            unset($lines[$i]['days'][$date]);
                        }
                        $removed[$date] = ($removed[$date] ?? 0) + $cut;
                    }
                }
                $lines = array_values(array_filter($lines, fn ($l) => ! empty($l['days'])));
                $changes[] = 'Removed '.array_sum($removed).' × '.$c->product->name;
                if ($d['type'] === 'substitute') {
                    $tpl = collect($r->lines)->first(fn ($l) => (int) $l['ref_id'] === $c->product_id) ?? [];
                    $lines[] = array_filter(['id' => 'sub'.$c->id, 'type' => 'item', 'ref_id' => (int) $d['substituteId'], 'days' => $removed,
                        'time' => $tpl['time'] ?? null, 'destId' => $tpl['destId'] ?? null, 'sourceId' => $tpl['sourceId'] ?? null], fn ($v) => $v !== null);
                    $changes[] = 'Added '.array_sum($removed).' × '.$d['substitute'];
                }
            }
            $refund = 0.0;
            if ($d['type'] === 'cancel') {
                $r->update(['status' => 'cancelled', 'cancel_reason' => 'Shortfall '.$c->code.' — cancelled by agreement']);
                RequestEvent::create(['rental_request_id' => $r->id, 'status' => 'cancelled', 'user_id' => $by->id, 'note' => 'Shortfall '.$c->code.' — cancelled by agreement', 'created_at' => now()]);
                $refund = r2($paidBefore['paidRental'] - $paidBefore['refunded']);
                $changes[] = 'Booking cancelled';
            } else {
                // The customer's quoted price is honored: only the CHANGE is applied to the stored snapshot —
                // the difference between quoting the old and the new lines today, then the agreed credit.
                $q = $r->pricing;
                if ($lines !== $r->lines) {
                    // removed units come off at the booked unit rates (patch the stored line rows) …
                    $delta = 0.0;
                    $cut = $removed;
                    foreach ($q['lines'] as $li => $ql) {
                        if (($ql['type'] ?? '') !== 'item' || (int) $ql['refId'] !== $c->product_id) {
                            continue;
                        }
                        foreach ($ql['days'] as $di => $row) {
                            $n = min((int) ($cut[$row['date']] ?? 0), (int) $row['qty']);
                            if ($n <= 0) {
                                continue;
                            }
                            $cut[$row['date']] -= $n;
                            $q['lines'][$li]['days'][$di]['qty'] = (int) $row['qty'] - $n;
                            $q['lines'][$li]['days'][$di]['subtotal'] = r2((float) $row['unit'] * ((int) $row['qty'] - $n));
                            $delta -= r2((float) $row['unit'] * $n);
                        }
                        $q['lines'][$li]['days'] = array_values(array_filter($q['lines'][$li]['days'], fn ($x) => $x['qty'] > 0));
                        $sum = r2(array_sum(array_column($q['lines'][$li]['days'], 'subtotal')));
                        $q['lines'][$li]['total'] = r2(max(0, $sum - ($ql['discount']['amount'] ?? 0)));
                        $q['lines'][$li]['rent'] = $q['lines'][$li]['total'];
                    }
                    // … and a substitute is added at its current price
                    $covered = 0.0;
                    if ($d['type'] === 'substitute') {
                        $sq = Pricing::quoteLine(end($lines), null, $r->created_at?->format('Y-m-d'));
                        $q['lines'][] = $sq;
                        $delta += (float) $sq['total'];
                        // the shop's shortfall never costs the customer more: a pricier substitute is on the shop
                        if ($delta > 0.009) {
                            $covered = r2($delta);
                            $q['credits'] = array_merge($q['credits'] ?? [], [['label' => 'Substitute price difference covered by '.$c->supplier->name.' · '.$c->code, 'amount' => $covered]]);
                            $changes[] = 'Shop covers the substitute\'s higher price ('.money($covered).')';
                            $delta = 0.0;
                        }
                    }
                    $q['lines'] = array_values(array_filter($q['lines'], fn ($x) => ! empty($x['days']) || ! empty($x['once'])));
                    foreach (['rentalFee', 'itemsSubtotal', 'rentalSubtotal', 'total', 'payable'] as $k) {
                        if (isset($q[$k])) {
                            $q[$k] = r2(max(0, (float) $q[$k] + $delta));
                        }
                    }
                    $r->update(['lines' => $lines]);
                }
                $credit = (float) ($d['credit'] ?? 0);
                $q['credits'] = array_merge($q['credits'] ?? [], $credit > 0 ? [['label' => 'Shortfall '.$c->code.' · '.self::TYPES[$d['type']], 'amount' => $credit]] : []);
                foreach (['rentalFee', 'itemsSubtotal', 'total', 'payable'] as $k) {
                    if (isset($q[$k])) {
                        $q[$k] = r2(max(0, (float) $q[$k] - $credit));
                    }
                }
                if (($d['comp']['payer'] ?? '') === 'customer') {
                    $amt = (float) $d['comp']['amount'];
                    $q['charges'] = array_merge($q['charges'] ?? [], [['label' => 'Rush costs · case '.$c->code.' · '.$d['comp']['reason'], 'amount' => $amt]]);
                    foreach (['rentalFee', 'total', 'payable'] as $k) {
                        if (isset($q[$k])) {
                            $q[$k] = r2((float) $q[$k] + $amt);
                        }
                    }
                    $changes[] = 'Customer pays '.money($amt).' rush costs ('.$d['comp']['reason'].')';
                }
                $q['finalBalance'] = r2(max(0, $q['total'] - ($q['reservationFee'] ?? 0) - ($q['downPayment'] ?? 0)));
                $r->update(['pricing' => $q]);
                $r->refresh();
                $r->finCache = null;
                $refund = r2(max(0, $paidBefore['paidRental'] - $paidBefore['refunded'] - $q['total']));
                if (abs($before['total'] - $q['total']) > 0.001) {
                    $changes[] = 'Total '.money($before['total']).' → '.money($q['total']);
                }
            }
            BookingDays::sync($r->fresh());
            inv()->flush();
            if ($refund > 0) {
                $refundPay = Payment::create([
                    'code' => Rentals::nextPaymentCode(), 'rental_request_id' => $r->id, 'type' => 'refund', 'method' => 'bank', 'amount' => $refund,
                    'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'SC-'.$c->id.'-'.substr((string) now()->getTimestampMs(), -5),
                    'status' => 'pending', 'notes' => 'Shortfall '.$c->code.' refund (agreed '.self::describe($d).')', 'recorded_by' => 'system',
                ]);
                // C13: paid online -> back through the gateway (Xendit / practice); otherwise the shop's cashier sends it
                $how = Refunds::issue($refundPay);
                if ($how === 'manual') {
                    Notifier::platformFinance('Refund due: '.$r->code, money($refund).' goes back to '.$c->customer->name.' (shortfall '.$c->code.', '.$c->supplier->name.'). Send it and record it.', route('cashier.record', ['request' => $r->code], false));
                }
                $changes[] = ($how === 'sent' ? 'Refunded ' : ($how === 'awaiting' ? 'Refund in progress ' : 'Refund due ')).money($refund);
            }
            $compOut = ($d['comp']['payer'] ?? '') === 'supplier' ? (float) $d['comp']['amount'] : 0.0;
            if ($compOut > 0) {
                // the shop compensates the customer: paid out like a refund (comes off the shop's payout)
                $compPay = Payment::create([
                    'code' => Rentals::nextPaymentCode(), 'rental_request_id' => $r->id, 'type' => 'refund', 'method' => 'bank', 'amount' => $compOut,
                    'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'SCC-'.$c->id.'-'.substr((string) now()->getTimestampMs(), -5),
                    'status' => 'pending', 'notes' => 'Compensation from the shop · case '.$c->code.' · '.$d['comp']['reason'], 'recorded_by' => 'system',
                ]);
                Notifier::platformFinance('Compensation due: '.$r->code, money($compOut).' goes to '.$c->customer->name.' (case '.$c->code.', '.$c->supplier->name.': '.$d['comp']['reason'].'). Send it and record it; it comes off the shop\'s payout.', route('cashier.record', ['request' => $r->code], false));
                $changes[] = 'Shop pays the customer '.money($compOut).' compensation ('.$d['comp']['reason'].')';
            }
            $res = ['type' => $d['type'], 'credit' => (float) ($d['credit'] ?? 0), 'refund' => $refund, 'comp' => $d['comp'] ?? null, 'by' => $role, 'proposal' => $e->id, 'changes' => $changes,
                'totalBefore' => $before['total'], 'totalAfter' => (float) ($r->fresh()->pricing['total'] ?? 0)];
            self::event($c, $role === 'admin' ? 'admin' : 'system', 'apply', implode(' · ', $changes) ?: 'Agreed — no change to the booking.', $res, $by);
            $c->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution' => $res, 'proposal_id' => null, 'deadline_at' => null]);
            $msg = 'Agreed: '.self::describe($d).'. '.(implode(' · ', $changes) ?: '');
            Notifier::notify($c->customer_id, 'customer', 'Case '.$c->code.' resolved', $msg, self::link($c));
            Notifier::notify($c->supplier->owner_id, 'supplier', 'Case '.$c->code.' resolved', $msg, self::link($c));
            Notifier::audit('Applied shortfall resolution', $c->code.' · '.$r->code.' · '.$msg, $by);

            return self::ok(['resolution' => $res]);
        });
    }
}
