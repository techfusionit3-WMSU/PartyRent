<?php

namespace App\Domain;

use App\Models\EventBooking;
use App\Models\EventPackage;
use App\Models\EventPayment;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Booking a business (event company): the customer sends an inquiry, the business replies with a quotation
 * (line items + payment schedule), the customer accepts and pays the reservation fee to hold the date,
 * then the deposit and the balance before the event. Payments go to the business's own account; the customer
 * sends proof and the business confirms it. 1-to-1 item rentals are for solo shops only.
 */
class Events
{
    /** Defaults for Supplier::bizSettings(). Percentages of the quoted total; days are before the event. */
    public const SETTINGS = [
        'serviceAreas' => [], 'travelNote' => '', 'setupHours' => 3, 'teardownHours' => 2, 'maxEventsPerDay' => 1,
        'reservePct' => 20, 'depositPct' => 30, 'depositDays' => 30, 'balanceDays' => 7,
        'reserveHours' => 48, 'quoteDays' => 7, 'minNoticeDays' => 7,
    ];

    private static function fail(string $m): array { return ['ok' => false, 'error' => $m]; }

    private static function code(string $prefix, string $model): string
    {
        return $prefix.'-'.(10000 + (int) $model::max('id') + 1);
    }

    /** Events the business already holds that day (booked or confirmed). */
    public static function heldOn(Supplier $s, string $date, ?int $except = null): int
    {
        return EventBooking::where('supplier_id', $s->id)->whereIn('status', ['booked', 'confirmed'])
            ->whereHas('parts', fn ($q) => $q->whereDate('date', $date))->when($except, fn ($q) => $q->where('id', '!=', $except))->count();
    }

    public static function dateFree(Supplier $s, string $date, ?int $except = null): bool
    {
        return self::heldOn($s, $date, $except) < max(1, (int) $s->bizSettings()['maxEventsPerDay']);
    }

    /* ---------- customer: inquiry ---------- */

    public static function inquire(User $u, Supplier $s, array $v, ?EventPackage $pkg): array
    {
        if (! $s->isOrganization() || $s->status !== 'approved') {
            return self::fail('This business is not taking bookings right now.');
        }
        if ($s->owner_id === $u->id || $u->shop()?->id === $s->id) {
            return self::fail('You cannot book your own business.');
        }
        if ($pkg && $pkg->supplier_id !== $s->id) {
            return self::fail('That package belongs to another business.');
        }
        // the parts of the event (one day, or several dates / stages) — each with its own date
        $built = EventParts::build(EventParts::definitions($pkg, (string) $v['event_type']), (array) ($v['parts'] ?? []), $v);
        if (! $built['ok']) {
            return self::fail($built['error']);
        }
        $parts = $built['parts'];
        $dates = EventParts::dates($parts);
        $set = $s->bizSettings();
        $min = today()->addDays((int) $set['minNoticeDays']);
        if (Carbon::parse($dates[0])->lt($min)) {
            return self::fail($s->name.' needs at least '.$set['minNoticeDays'].' days\' notice before the first part. Choose '.fmt_date($min->toDateString()).' or later.');
        }
        // every date free, and only kinds of events PartyRent verified for this business, within its limits
        if ($e = EventParts::capacityError($s, (string) $v['event_type'], (int) $v['guests'], $dates)) {
            return self::fail($e);
        }
        $first = $parts[0];
        $b = DB::transaction(function () use ($s, $u, $pkg, $v, $parts, $first) {
            $b = EventBooking::create([
                'code' => self::code('EVT', EventBooking::class), 'supplier_id' => $s->id, 'customer_id' => $u->id, 'event_package_id' => $pkg?->id,
                'status' => 'inquiry', 'event_type' => $v['event_type'], 'event_date' => $first['date'],
                'start_time' => $first['start_time'], 'end_time' => $first['end_time'], 'guests' => (int) $v['guests'],
                'venue' => trim($v['venue']), 'city' => $v['city'] ?? null, 'budget' => $v['budget'] ?? null, 'notes' => trim((string) ($v['notes'] ?? '')),
            ]);
            foreach ($parts as $p) {
                $b->parts()->create($p);
            }

            return $b;
        });
        $when = count($parts) > 1 ? count($parts).' parts from '.fmt_date($dates[0]).' to '.fmt_date(end($dates)) : fmt_date($dates[0]);
        Notifier::notify($s->owner, 'supplier', 'New event inquiry · '.$b->code, $u->name.' asks about a '.$b->event_type.' ('.$when.') for '.$b->guests.' guests'.($pkg ? ' ('.$pkg->name.')' : '').'. Send a quotation.', route('business.bookings.show', $b, false));

        return ['ok' => true, 'booking' => $b];
    }

    /* ---------- business: quotation ---------- */

    /** The payment schedule for a total, using the business's percentages (a quote may change them). */
    public static function schedule(Supplier $s, float $total, string $eventDate, array $pct = []): array
    {
        $set = array_replace($s->bizSettings(), array_filter($pct, fn ($x) => $x !== null && $x !== ''));
        $reserve = round($total * (float) $set['reservePct'] / 100, 2);
        $deposit = round($total * (float) $set['depositPct'] / 100, 2);
        $balance = round($total - $reserve - $deposit, 2);
        $event = Carbon::parse($eventDate);
        $due = fn (int $days) => max(today()->toDateString(), $event->copy()->subDays($days)->toDateString());

        return array_values(array_filter([
            ['kind' => 'reservation', 'label' => 'Reservation fee', 'pct' => (float) $set['reservePct'], 'amount' => $reserve, 'due' => 'on acceptance'],
            ['kind' => 'deposit', 'label' => 'Deposit', 'pct' => (float) $set['depositPct'], 'amount' => $deposit, 'due' => $due((int) $set['depositDays'])],
            ['kind' => 'balance', 'label' => 'Balance', 'pct' => round(100 - $set['reservePct'] - $set['depositPct'], 2), 'amount' => $balance, 'due' => $due((int) $set['balanceDays'])],
        ], fn ($x) => $x['amount'] > 0));
    }

    public static function quote(EventBooking $b, User $by, array $v): array
    {
        if (! in_array($b->status, ['inquiry', 'quoted'], true)) {
            return self::fail('This booking can no longer be quoted.');
        }
        $items = [];
        foreach ($v['items'] ?? [] as $it) {
            $label = trim((string) ($it['label'] ?? ''));
            if ($label === '' && ($it['amount'] ?? '') === '') {
                continue;
            }
            if ($label === '' || ! is_numeric($it['amount'] ?? null) || $it['amount'] < 0) {
                return self::fail('Every quotation line needs a description and an amount of ₱0 or more.');
            }
            $items[] = ['label' => mb_substr($label, 0, 120), 'amount' => round((float) $it['amount'], 2)];
        }
        $total = round(array_sum(array_column($items, 'amount')), 2);
        if (! $items || $total <= 0) {
            return self::fail('Add at least one line with an amount.');
        }
        $pct = ['reservePct' => $v['reservePct'] ?? null, 'depositPct' => $v['depositPct'] ?? null];
        if ((float) ($pct['reservePct'] ?? 0) + (float) ($pct['depositPct'] ?? 0) > 100) {
            return self::fail('The reservation fee and deposit together cannot be more than 100%.');
        }
        $days = max(1, min(30, (int) ($v['valid_days'] ?? $b->supplier->bizSettings()['quoteDays'])));
        $b->update([
            'status' => 'quoted', 'quoted_at' => now(),
            'quote' => [
                'items' => $items, 'total' => $total, 'note' => trim((string) ($v['note'] ?? '')),
                'schedule' => self::schedule($b->supplier, $total, $b->event_date->toDateString(), $pct),
                'validUntil' => today()->addDays($days)->toDateString(), 'by' => $by->id,
            ],
        ]);
        Notifier::notify($b->customer_id, 'customer', 'Quotation from '.$b->supplier->name, 'Your '.$b->event_type.' on '.fmt_date($b->event_date->toDateString()).': '.money($total).'. Accept it by '.fmt_date($b->quote['validUntil']).' to book the date.', route('events.show', $b, false));
        Notifier::audit('Sent quotation', $b->code.' · '.money($total), $by);

        return ['ok' => true];
    }

    public static function decline(EventBooking $b, User $by, string $reason): array
    {
        if (! in_array($b->status, ['inquiry', 'quoted'], true)) {
            return self::fail('Only inquiries and open quotations can be declined.');
        }
        $b->update(['status' => 'declined', 'closed_at' => now(), 'close_reason' => $reason, 'closed_by' => 'business']);
        Notifier::notify($b->customer_id, 'customer', $b->supplier->name.' cannot take your event', $b->code.($reason !== '' ? ': '.$reason : '.'), route('events.show', $b, false));

        return ['ok' => true];
    }

    /* ---------- customer: accept / decline the quotation ---------- */

    public static function accept(EventBooking $b, User $u): array
    {
        if ($b->customer_id !== $u->id || $b->status !== 'quoted') {
            return self::fail('This quotation cannot be accepted.');
        }
        if (Carbon::parse($b->quote['validUntil'])->lt(today())) {
            $b->update(['status' => 'expired', 'closed_at' => now(), 'closed_by' => 'system', 'close_reason' => 'Quotation expired']);

            return self::fail('This quotation has expired. Ask the business for a new one.');
        }
        if ($e = EventParts::capacityError($b->supplier, $b->event_type, (int) $b->guests, EventParts::bookingDates($b), $b->id)) {
            return self::fail('Sorry, it can no longer be booked: '.$e);
        }
        $hours = (int) $b->supplier->bizSettings()['reserveHours'];
        DB::transaction(function () use ($b, $hours) {
            foreach ($b->quote['schedule'] as $step) {
                EventPayment::create([
                    'code' => self::code('EVP', EventPayment::class), 'event_booking_id' => $b->id, 'kind' => $step['kind'], 'label' => $step['label'],
                    'amount' => $step['amount'], 'due_date' => $step['kind'] === 'reservation' ? now()->addHours($hours)->toDateString() : $step['due'], 'status' => 'due',
                ]);
            }
            $noFee = ! collect($b->quote['schedule'])->contains('kind', 'reservation'); // 0% reservation fee: the date is held right away
            $b->update(['status' => $noFee ? 'confirmed' : 'booked', 'accepted_at' => now()] + ($noFee ? ['confirmed_at' => now()] : []));
        });
        Notifier::notify($b->supplier->owner, 'supplier', 'Quotation accepted · '.$b->code, $u->name.' accepted '.money($b->total()).'. The date is held once the reservation fee is paid.', route('business.bookings.show', $b, false));

        return ['ok' => true];
    }

    public static function cancel(EventBooking $b, User $by, string $who, string $reason): array
    {
        if (! $b->isOpen()) {
            return self::fail('This booking is already closed.');
        }
        if ($who === 'customer' && $b->customer_id !== $by->id) {
            return self::fail('This is not your booking.');
        }
        $b->update(['status' => 'cancelled', 'closed_at' => now(), 'close_reason' => $reason, 'closed_by' => $who]);
        $b->payments()->where('status', '!=', 'paid')->delete();
        $paid = $b->paid();
        $refundNote = $paid > 0 ? ' '.money($paid).' was already paid; refunds follow '.$b->supplier->name.'\'s cancellation policy.' : '';
        if ($who === 'customer') {
            Notifier::notify($b->supplier->owner, 'supplier', 'Booking cancelled by the customer · '.$b->code, $by->name.' cancelled the '.$b->event_type.' on '.fmt_date($b->event_date->toDateString()).'.'.$refundNote, route('business.bookings.show', $b, false));
        } else {
            Notifier::notify($b->customer_id, 'customer', $b->supplier->name.' cancelled your booking', $b->code.($reason !== '' ? ': '.$reason : '.').$refundNote, route('events.show', $b, false));
        }

        return ['ok' => true];
    }

    /* ---------- payments ---------- */

    public static function submitPayment(EventPayment $p, User $u, array $v, ?string $proof): array
    {
        $b = $p->booking;
        if ($b->customer_id !== $u->id || ! in_array($b->status, ['booked', 'confirmed'], true) || $p->status !== 'due') {
            return self::fail('This payment cannot be sent right now.');
        }
        $p->update(['status' => 'pending', 'method' => $v['method'], 'reference' => trim($v['reference']), 'proof_path' => $proof, 'submitted_at' => now(), 'reject_reason' => null]);
        Notifier::notify($b->supplier->owner, 'supplier', 'Payment to confirm · '.$b->code, $u->name.' sent the '.mb_strtolower($p->label).' of '.money($p->amount).' (ref '.$p->reference.'). Check your account and confirm it.', route('business.bookings.show', $b, false));

        return ['ok' => true];
    }

    /** $by = null when a payment gateway confirmed it (paid online). */
    public static function confirmPayment(EventPayment $p, ?User $by, bool $ok, string $reason = ''): array
    {
        $b = $p->booking;
        if ($p->status !== 'pending') {
            return self::fail('There is no payment proof to check.');
        }
        if (! $ok) {
            $p->update(['status' => 'due', 'reject_reason' => $reason ?: 'Payment not found', 'proof_path' => null]);
            Notifier::notify($b->customer_id, 'customer', 'Payment not confirmed · '.$b->code, $b->supplier->name.' could not find your '.mb_strtolower($p->label).' of '.money($p->amount).': '.$p->reject_reason.'. Please check and send it again.', route('events.show', $b, false));

            return ['ok' => true];
        }
        if ($p->kind === 'reservation' && $b->status === 'booked') {
            foreach (EventParts::bookingDates($b) as $d) {
                if (! self::dateFree($b->supplier, $d, $b->id)) {
                    return self::fail(fmt_date($d).' is already full. Cancel this booking or raise "events per day" in Business settings first.');
                }
            }
        }
        DB::transaction(function () use ($p, $b, $by) {
            $p->update(['status' => 'paid', 'paid_at' => now(), 'confirmed_by' => $by?->id, 'receipt_no' => 'EOR-'.(10000 + (int) EventPayment::whereNotNull('receipt_no')->count() + 1)]);
            if ($p->kind === 'reservation' && $b->status === 'booked') {
                $b->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            }
        });
        if (! $by) {
            Notifier::notify($b->supplier->owner, 'supplier', 'Paid online · '.$b->code, $b->customer->name.' paid the '.mb_strtolower($p->label).' of '.money($p->amount).' online. PartyRent sends it to your payout account.', route('business.bookings.show', $b, false));
        }
        Notifier::notify($b->customer_id, 'customer', 'Payment confirmed · '.$b->code, $b->supplier->name.' received your '.mb_strtolower($p->label).' of '.money($p->amount).'.'.($b->fresh()->status === 'confirmed' && $p->kind === 'reservation' ? ' Your date is booked! 🎉' : ''), route('events.show', $b, false));

        return ['ok' => true];
    }

    /** Remind the customer of a payment that is due (at most once every 6 hours per payment). */
    public static function remind(EventPayment $p, User $by): array
    {
        $b = $p->booking;
        if ($p->status !== 'due' || ! in_array($b->status, ['booked', 'confirmed'], true)) {
            return self::fail('Only unpaid payments of an active booking can be reminded.');
        }
        $key = 'event-remind:'.$p->id;
        if (\Illuminate\Support\Facades\Cache::has($key)) {
            return self::fail('A reminder was already sent in the last 6 hours.');
        }
        \Illuminate\Support\Facades\Cache::put($key, true, now()->addHours(6));
        Notifier::notify($b->customer_id, 'customer', 'Payment reminder · '.$b->code, $b->supplier->name.': your '.mb_strtolower($p->label).' of '.money($p->amount).' is '.($p->isOverdue() ? 'overdue since ' : 'due on ').fmt_date($p->due_date->toDateString()).'.', route('events.show', $b, false));
        Notifier::audit('Payment reminder', $b->code.' · '.$p->label, $by);

        return ['ok' => true];
    }

    public static function complete(EventBooking $b, User $by): array
    {
        if ($b->status !== 'confirmed') {
            return self::fail('Only confirmed bookings can be completed.');
        }
        $last = $b->lastDate();
        if ($last->gt(today())) {
            return self::fail('You can mark the event done on or after its last part ('.fmt_date($last->toDateString()).').');
        }
        $b->update(['status' => 'completed', 'completed_at' => now()]);
        $b->parts()->whereNull('done_at')->update(['done_at' => now()]);
        Notifier::notify($b->customer_id, 'customer', 'Thank you for booking '.$b->supplier->name, 'Your '.$b->event_type.' ('.$b->code.') is marked as done.', route('events.show', $b, false));

        return ['ok' => true];
    }

    /* ---------- after the event ---------- */

    /** One review per completed booking; it rates the business (shown on its page, counts in its rating). */
    public static function review(EventBooking $b, User $u, int $rating, string $comment, array $photos = []): array
    {
        if ($b->customer_id !== $u->id || $b->status !== 'completed') {
            return self::fail('You can review an event once it is done.');
        }
        if (\App\Models\Review::where('event_booking_id', $b->id)->exists()) {
            return self::fail('You already reviewed this event.');
        }
        if ($rating < 1 || $rating > 5 || mb_strlen(trim($comment)) < Reviews::MIN_COMMENT) {
            return self::fail('Choose 1 to 5 stars and write at least '.Reviews::MIN_COMMENT.' characters.');
        }
        $media = array_map(fn ($f) => ['type' => 'image', 'path' => $f->store('reviews/events/'.$b->id, 'public'), 'mime' => $f->getMimeType()], array_slice($photos, 0, Reviews::MAX_PHOTOS));
        \App\Models\Review::create([
            'supplier_id' => $b->supplier_id, 'event_booking_id' => $b->id, 'customer_id' => $u->id,
            'rating' => $rating, 'comment' => trim($comment), 'media' => $media ?: null,
        ]);
        Reviews::recalcSupplier($b->supplier_id);
        Notifier::notify($b->supplier->owner, 'supplier', 'New review · '.$b->code, $u->name.' rated your '.$b->event_type.' '.$rating.'★.', route('supplier.reviews', [], false));

        return ['ok' => true];
    }

    /** PartyRent sent an online payment on to the business's payout account. */
    public static function remit(EventPayment $p, User $by): array
    {
        if ($p->status !== 'paid' || ! $p->gateway || $p->remitted_at) {
            return self::fail('Only online payments not yet sent can be marked as sent.');
        }
        $p->update(['remitted_at' => now(), 'remitted_by' => $by->id]);
        $b = $p->booking;
        Notifier::notify($b->supplier->owner, 'supplier', 'Payment sent to you · '.$b->code, 'PartyRent sent '.money($p->amount).' ('.$p->label.', paid online) to your payout account.', route('business.payments', [], false));
        Notifier::audit('Sent event payment to business', $b->code.' · '.$p->code.' · '.money($p->amount), $by);

        return ['ok' => true];
    }

    /**
     * Daily (scheduler): expire old quotations and unpaid reservations, and remind customers of payments due
     * in 3 days, due today, or overdue (each payment at most once a day).
     */
    public static function daily(): array
    {
        $expired = self::expire();
        $sent = 0;
        $pay = EventPayment::where('status', 'due')->whereDate('due_date', '<=', today()->addDays(3))
            ->whereHas('booking', fn ($q) => $q->whereIn('status', ['booked', 'confirmed']))->with('booking.supplier')->get();
        foreach ($pay as $p) {
            $days = (int) today()->diffInDays($p->due_date, false);
            if (! in_array($days, [3, 0], true) && $days >= 0) {
                continue; // remind 3 days before, on the day, and every day once overdue
            }
            $key = 'event-auto-remind:'.$p->id.':'.today()->toDateString();
            if (\Illuminate\Support\Facades\Cache::add($key, true, now()->addDay())) {
                $b = $p->booking;
                $when = $days > 0 ? 'is due in 3 days ('.fmt_date($p->due_date->toDateString()).')' : ($days === 0 ? 'is due today' : 'is overdue since '.fmt_date($p->due_date->toDateString()));
                Notifier::notify($b->customer_id, 'customer', 'Payment reminder · '.$b->code, 'Your '.mb_strtolower($p->label).' of '.money($p->amount).' for '.$b->supplier->name.' '.$when.'.', route('events.show', $b, false));
                $sent++;
            }
        }

        return ['expired' => $expired, 'reminders' => $sent];
    }

    /** Quotations not accepted in time, and booked dates whose reservation fee was never paid, close themselves. */
    public static function expire(): int
    {
        $n = 0;
        foreach (EventBooking::where('status', 'quoted')->get() as $b) {
            if (Carbon::parse($b->quote['validUntil'] ?? today())->lt(today())) {
                $b->update(['status' => 'expired', 'closed_at' => now(), 'closed_by' => 'system', 'close_reason' => 'Quotation expired']);
                $n++;
            }
        }
        foreach (EventBooking::where('status', 'booked')->with('payments')->get() as $b) {
            $r = $b->payments->firstWhere('kind', 'reservation');
            if ($r && $r->status === 'due' && $r->due_date->lt(today())) {
                $b->update(['status' => 'expired', 'closed_at' => now(), 'closed_by' => 'system', 'close_reason' => 'Reservation fee not paid in time']);
                $b->payments()->where('status', '!=', 'paid')->delete();
                Notifier::notify($b->customer_id, 'customer', 'Booking released · '.$b->code, 'The reservation fee was not paid in time, so '.$b->supplier->name.' released the date.', route('events.show', $b, false));
                $n++;
            }
        }

        return $n;
    }
}
