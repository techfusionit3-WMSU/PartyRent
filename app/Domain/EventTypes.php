<?php

namespace App\Domain;

use App\Models\EventBooking;
use App\Models\EventPackage;
use App\Models\EventType;
use App\Models\Supplier;
use App\Models\SupplierEventType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * What kinds of events a business can handle (user 2026-11-22), for every kind of event, not weddings only.
 *
 * PartyRent keeps a catalog of event types. A business applies for each type it handles: the biggest guest count it
 * can serve, how many of those events it can run in one day, how many it has done, photos of past ones and the
 * permits the type needs. PartyRent verifies each type with its own limits (they can be lower than claimed).
 * Only verified types can be offered: packages must be for verified types and within the verified guest count,
 * customers can only ask for verified types, and a day can only hold as many events of a type as verified.
 * A business can ask for more later (raise) — the old limits apply until PartyRent agrees. PartyRent can pause one
 * type without touching the others.
 */
class EventTypes
{
    /** The starting catalog: name, description, typical guests, parts (stages / days), usual services, proof needed. */
    public const CATALOG = [
        ['Wedding', 'Ceremony and reception, often with a prenup shoot before.', 80, 300, [['Prenup shoot', true, -60], ['Ceremony', false, 0], ['Reception', false, 0]], ['Coordination', 'Styling & décor', 'Catering', 'Lights & sound', 'Photo & video', 'Host'], []],
        ['Debut', '18th birthday with the traditional program.', 80, 200, [['Rehearsal', true, -1], ['Debut night', false, 0]], ['Coordination', 'Styling & décor', 'Catering', 'Lights & sound', 'Host'], []],
        ['Birthday', 'Birthdays for kids and adults.', 20, 150, [['Party', false, 0]], ['Styling & décor', 'Host', 'Catering', 'Entertainment'], []],
        ['Christening', 'Baptism and the reception after.', 30, 150, [['Ceremony', true, 0], ['Reception', false, 0]], ['Styling & décor', 'Catering'], []],
        ['Wedding Anniversary', 'Silver, golden and other anniversaries.', 30, 200, [['Celebration', false, 0]], ['Styling & décor', 'Catering', 'Host'], []],
        ['Baby Shower / Gender Reveal', 'Baby showers and gender reveal parties.', 15, 80, [['Party', false, 0]], ['Styling & décor', 'Catering'], []],
        ['Graduation', 'Graduation parties and recognition events.', 20, 300, [['Celebration', false, 0]], ['Styling & décor', 'Catering', 'Lights & sound'], []],
        ['Reunion', 'Family, class and alumni reunions.', 30, 300, [['Reunion', false, 0]], ['Catering', 'Lights & sound', 'Host'], []],
        ['Corporate Party', 'Company parties, year-end and awards nights.', 50, 500, [['Setup', true, -1], ['Event night', false, 0]], ['Coordination', 'Stage & LED wall', 'Lights & sound', 'Catering', 'Host'], []],
        ['Seminar / Conference', 'Talks, trainings and conferences, one or several days.', 20, 1000, [['Setup day', true, -1], ['Day 1', false, 0], ['Day 2', true, 1], ['Teardown', true, 2]], ['Coordination', 'Stage', 'Lights & sound', 'Registration desk', 'Catering'], []],
        ['Team Building', 'Company outings and team building activities.', 20, 300, [['Activity day', false, 0]], ['Facilitation', 'Catering', 'Games & equipment'], []],
        ['Product Launch', 'Brand and product launches, press events.', 30, 500, [['Setup', true, -1], ['Launch', false, 0]], ['Coordination', 'Stage & LED wall', 'Lights & sound', 'Styling & décor'], []],
        ['Fiesta / Community Event', 'Town fiestas, barangay and community events, often several days.', 100, 3000, [['Day 1', false, 0], ['Day 2', true, 1], ['Day 3', true, 2]], ['Stage', 'Lights & sound', 'Tents', 'Crowd control'], ['Barangay or LGU permit']],
        ['Concert / Show', 'Concerts, shows and performances.', 100, 5000, [['Ingress', false, -1], ['Show', false, 0], ['Egress', false, 1]], ['Stage', 'Lights & sound', 'Security'], ['LGU event permit', 'Security agency contract']],
        ['School / Church Event', 'School programs, church events and recollections.', 30, 1000, [['Event', false, 0]], ['Stage', 'Lights & sound', 'Styling & décor'], []],
        ['Wake / Memorial', 'Wakes and memorial services, usually several nights.', 20, 300, [['Night 1', false, 0], ['Night 2', true, 1], ['Night 3', true, 2], ['Final night', true, 3]], ['Tents', 'Chairs & tables', 'Catering', 'Lights'], []],
        ['House Blessing', 'House and office blessings.', 10, 100, [['Blessing & reception', false, 0]], ['Catering', 'Styling & décor'], []],
        ['Holiday Party', 'Christmas and New Year parties.', 20, 500, [['Party', false, 0]], ['Styling & décor', 'Catering', 'Lights & sound', 'Host'], []],
    ];

    /** Proof every catered type needs (catering on the business's list). */
    public const FOOD_PROOF = 'Food handling / sanitary permit (if you serve food)';

    /** Event sizes of the old business profile → a guest count. */
    private const SIZE_GUESTS = ['up_to_50' => 50, '50_150' => 150, '150_300' => 300, '300_plus' => 500];

    private static function fail(string $m): array { return ['ok' => false, 'error' => $m]; }

    public static function seedCatalog(): void
    {
        foreach (self::CATALOG as $i => [$name, $desc, $min, $max, $parts, $services, $proofs]) {
            EventType::firstOrCreate(['name' => $name], [
                'description' => $desc, 'min_guests' => $min, 'max_guests' => $max, 'sort' => ($i + 1) * 10, 'status' => 'active',
                'parts' => array_map(fn ($p) => ['name' => $p[0], 'optional' => $p[1], 'offset' => $p[2]], $parts),
                'services' => $services, 'proofs' => $proofs,
            ]);
        }
    }

    /** A free-typed event name ("kasal", "Corporate") → a catalog type. */
    public static function match(?string $name): ?EventType
    {
        $n = mb_strtolower(trim((string) $name));
        if ($n === '') {
            return null;
        }
        $types = EventType::where('status', 'active')->get();
        if ($t = $types->first(fn ($t) => mb_strtolower($t->name) === $n)) {
            return $t;
        }
        // the old fixed list and its words (Ai::EVENTS), then a type whose name starts with or contains it
        $alias = ['corporate' => 'Corporate Party', 'baby shower' => 'Baby Shower / Gender Reveal', 'anniversary' => 'Wedding Anniversary'];
        foreach (Ai::EVENTS as $key => $words) {
            if (mb_strtolower($key) === $n || in_array($n, $words, true)) {
                $n = mb_strtolower($alias[mb_strtolower($key)] ?? $key);
                break;
            }
        }

        return $types->first(fn ($t) => mb_strtolower($t->name) === $n) ?? $types->first(fn ($t) => str_starts_with(mb_strtolower($t->name), $n));
    }

    /**
     * Event companies that existed before these checks keep what they already offer: every event type their packages
     * or profile name is verified with their current numbers (largest package, or the profile's event size).
     */
    public static function grandfather(Supplier $s): void
    {
        $perDay = max(1, (int) $s->bizSettings()['maxEventsPerDay']);
        $sizeGuests = self::SIZE_GUESTS[$s->profile('eventSize')] ?? 150;
        $byType = [];
        foreach ($s->eventPackages()->get() as $k) {
            foreach ((array) $k->events as $e) {
                if ($t = self::match($e)) {
                    $byType[$t->id] = max($byType[$t->id] ?? 0, (int) ($k->max_guests ?: $k->min_guests ?: $sizeGuests));
                }
            }
        }
        foreach ((array) $s->profile('events', []) as $e) {
            if (($t = self::match($e)) && ! isset($byType[$t->id])) {
                $byType[$t->id] = $sizeGuests;
            }
        }
        foreach ($byType as $tid => $guests) {
            SupplierEventType::firstOrCreate(['supplier_id' => $s->id, 'event_type_id' => $tid], [
                'status' => $s->status === 'approved' ? 'verified' : 'pending', 'claimed_max_guests' => $guests, 'claimed_per_day' => $perDay,
                'max_guests' => $s->status === 'approved' ? $guests : null, 'per_day' => $s->status === 'approved' ? $perDay : null,
                'note' => 'Offered before event type checks.', 'reviewed_at' => $s->status === 'approved' ? now() : null,
            ]);
        }
        // packages and bookings keep using the catalog names
        foreach ($s->eventBookings()->get() as $b) {
            if (($t = self::match($b->event_type)) && $t->name !== $b->event_type) {
                $b->update(['event_type' => $t->name]);
            }
        }
        foreach ($s->eventPackages()->get() as $k) {
            $names = array_values(array_unique(array_filter(array_map(fn ($e) => self::match($e)?->name, (array) $k->events))));
            if ($names && $names !== $k->events) {
                $k->update(['events' => $names]);
            }
        }
    }

    /**
     * A newly approved event company: the events it listed when applying become applications waiting for review
     * (no limits yet). It adds photos / permits on the Event Types page; nothing is offered until PartyRent verifies.
     */
    public static function fromApplication(Supplier $s): void
    {
        $guests = self::SIZE_GUESTS[$s->profile('eventSize')] ?? 100;
        $perDay = max(1, (int) $s->bizSettings()['maxEventsPerDay']);
        foreach ((array) $s->profile('events', []) as $e) {
            if ($t = self::match($e)) {
                SupplierEventType::firstOrCreate(['supplier_id' => $s->id, 'event_type_id' => $t->id], [
                    'status' => 'pending', 'claimed_max_guests' => $guests, 'claimed_per_day' => $perDay, 'past_events' => 0, 'note' => 'Listed in the supplier application.',
                ]);
            }
        }
    }

    /* ---------- what a business may offer ---------- */

    /** The business's verified types, keyed by type name. */
    public static function verified(Supplier $s): Collection
    {
        return $s->eventTypes()->where('status', 'verified')->with('type')->get()->filter(fn ($x) => $x->type && $x->type->status === 'active')->keyBy(fn ($x) => $x->type->name);
    }

    /** Proof a type needs from this business (its own list + food permit when catering is one of its services). */
    public static function proofsFor(EventType $t): array
    {
        $p = (array) $t->proofs;
        if (in_array('Catering', (array) $t->services, true)) {
            $p[] = self::FOOD_PROOF;
        }

        return array_values(array_unique($p));
    }

    /** Why a package with these event types and guest count cannot be offered (null = fine). */
    public static function packageError(Supplier $s, array $names, ?int $maxGuests): ?string
    {
        $ok = self::verified($s);
        if ($ok->isEmpty()) {
            return 'Get at least one event type verified first (Event Types page). Packages can only be offered for event types PartyRent has checked.';
        }
        if (! $names) {
            return 'Choose at least one event type this package is for.';
        }
        foreach ($names as $n) {
            $v = $ok->get($n);
            if (! $v) {
                return $n.' is not one of your verified event types. Apply for it on the Event Types page first.';
            }
            if ($maxGuests && $maxGuests > (int) $v->max_guests) {
                return 'You are verified for '.$n.' events of up to '.number_format((int) $v->max_guests).' guests. Lower the maximum guests, or ask PartyRent for a higher limit on the Event Types page.';
            }
        }

        return null;
    }

    /** Can customers see this package? (active, and at least one of its types is verified) */
    public static function offerable(EventPackage $k, ?Collection $verified = null): bool
    {
        $verified ??= self::verified($k->supplier);

        return $k->active && collect((array) $k->events)->contains(fn ($n) => $verified->has($n));
    }

    /** Events of one type the business holds that day (booked or confirmed). */
    public static function heldOn(Supplier $s, string $type, string $date, ?int $except = null): int
    {
        return EventBooking::where('supplier_id', $s->id)->whereIn('status', ['booked', 'confirmed'])->where('event_type', $type)
            ->whereHas('parts', fn ($q) => $q->whereDate('date', $date))->when($except, fn ($q) => $q->where('id', '!=', $except))->count();
    }

    /** Why a customer cannot ask for this event (null = fine): type not handled, too many guests, type full that day. */
    public static function inquiryError(Supplier $s, string $type, int $guests, string $date, ?int $except = null): ?string
    {
        $v = self::verified($s)->get($type);
        if (! $v) {
            return $s->name.' does not take '.$type.' events on PartyRent. Choose one of the event types they offer.';
        }
        if ($guests > (int) $v->max_guests) {
            return $s->name.' handles '.$type.' events of up to '.number_format((int) $v->max_guests).' guests. Lower the guest count or choose another business.';
        }
        if (self::heldOn($s, $type, $date, $except) >= max(1, (int) $v->per_day)) {
            return $s->name.' is fully booked for '.$type.' events on '.fmt_date($date).'. Try another date.';
        }

        return null;
    }

    /* ---------- business: apply for a type, ask for a new one ---------- */

    /**
     * Apply for a type, or update the claim. New or not-approved → waits for review. Verified and asking for more
     * guests or more events a day → the old limits stay until PartyRent reviews the raise.
     *
     * @param  UploadedFile[]  $photos
     * @param  array<string, UploadedFile>  $docs  proof label => file
     */
    public static function apply(Supplier $s, User $by, EventType $t, array $v, array $photos, array $docs, array $keepPhotos = []): array
    {
        if (! $s->isOrganization()) {
            return self::fail('Only event companies apply for event types.');
        }
        if ($t->status !== 'active') {
            return self::fail('That event type is not open yet.');
        }
        $row = SupplierEventType::firstOrNew(['supplier_id' => $s->id, 'event_type_id' => $t->id]);
        if ($row->status === 'paused') {
            return self::fail('PartyRent paused your '.$t->name.' events'.($row->reason ? ' ('.$row->reason.')' : '').'. Contact PartyRent support to resume them.');
        }
        $dir = 'event-proofs/'.$s->id;
        $kept = array_values(array_intersect((array) $row->photos, $keepPhotos));
        $allPhotos = array_slice(array_merge($kept, array_map(fn ($f) => $f->store($dir, 'local'), $photos)), 0, 8);
        if (! $allPhotos) {
            return self::fail('Add at least one photo of a '.$t->name.' event you handled, so PartyRent can see your work.');
        }
        $allDocs = (array) $row->docs;
        foreach ($docs as $label => $f) {
            if (in_array($label, self::proofsFor($t), true) && $f instanceof UploadedFile) {
                $allDocs[$label] = $f->store($dir, 'local');
            }
        }
        foreach (self::proofsFor($t) as $need) {
            if ($need !== self::FOOD_PROOF && empty($allDocs[$need])) {
                return self::fail($t->name.' events need: '.$need.'. Upload it to apply.');
            }
        }
        $claim = ['claimed_max_guests' => (int) $v['max_guests'], 'claimed_per_day' => (int) $v['per_day'], 'past_events' => (int) ($v['past_events'] ?? 0),
            'note' => trim((string) ($v['note'] ?? '')) ?: null, 'photos' => $allPhotos, 'docs' => $allDocs];
        if ($row->exists && $row->status === 'verified') {
            $raise = $claim['claimed_max_guests'] > (int) $row->max_guests || $claim['claimed_per_day'] > (int) $row->per_day;
            $row->fill($claim + ['raise_pending' => $raise])->save();
            if ($raise) {
                Notifier::notify('admin', 'admin', 'Check a higher limit: '.$s->name, $s->name.' asks to handle '.$t->name.' events of up to '.number_format($claim['claimed_max_guests']).' guests, '.$claim['claimed_per_day'].' a day.', route('admin.event-types', ['tab' => 'review'], false));
            }

            return ['ok' => true, 'row' => $row, 'raise' => $raise];
        }
        $row->fill($claim + ['status' => 'pending', 'raise_pending' => false, 'reason' => null])->save();
        Notifier::notify('admin', 'admin', 'Check event type: '.$t->name.' · '.$s->name, $s->name.' applied to handle '.$t->name.' events (up to '.number_format($claim['claimed_max_guests']).' guests, '.$claim['claimed_per_day'].' a day, '.$claim['past_events'].' done before).', route('admin.event-types', ['tab' => 'review'], false));
        Notifier::audit('Applied for event type', $s->name.' · '.$t->name, $by);

        return ['ok' => true, 'row' => $row, 'raise' => false];
    }

    /** A kind of event the catalog does not have yet: PartyRent adds it (or points to an existing one). */
    public static function requestType(Supplier $s, string $name, ?string $note): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 3) {
            return self::fail('Type the name of the event, e.g. "Pet birthday".');
        }
        if ($t = EventType::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()) {
            return $t->status === 'active' ? self::fail($t->name.' is already on the list: apply for it below.') : self::fail($t->name.' was already asked for. PartyRent will reply soon.');
        }
        $t = EventType::create(['name' => mb_substr($name, 0, 60), 'status' => 'requested', 'requested_by' => $s->id, 'request_note' => $note ? mb_substr(trim($note), 0, 500) : null, 'sort' => 900]);
        Notifier::notify('admin', 'admin', 'New event type asked for: '.$t->name, $s->name.' asks PartyRent to add "'.$t->name.'".'.($note ? ' '.$note : ''), route('admin.event-types', ['tab' => 'catalog'], false));

        return ['ok' => true, 'type' => $t];
    }

    /* ---------- PartyRent: review ---------- */

    public static function review(SupplierEventType $row, User $by, bool $approve, ?int $maxGuests, ?int $perDay, ?string $reason): array
    {
        $t = $row->type;
        $s = $row->supplier;
        if (! $approve) {
            if (! trim((string) $reason)) {
                return self::fail('Say why, so the business knows what to fix.');
            }
            if ($row->raise_pending) { // a raise turned down: keep the verified limits
                $row->update(['raise_pending' => false, 'reason' => $reason, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
                Notifier::notify($s->owner_id, 'supplier', 'Higher limit not approved: '.$t->name, 'You stay at '.$row->limitsLabel().' for '.$t->name.' events. Reason: '.$reason, route('business.event-types', [], false));
            } else {
                $row->update(['status' => 'rejected', 'reason' => $reason, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
                Notifier::notify($s->owner_id, 'supplier', $t->name.' events not approved yet', 'Reason: '.$reason.' Update your proof on the Event Types page and apply again.', route('business.event-types', [], false));
            }
            Notifier::audit('Did not approve event type', $s->name.' · '.$t->name.' — '.$reason, $by);

            return ['ok' => true];
        }
        $max = max(1, min((int) ($maxGuests ?: $row->claimed_max_guests), $row->claimed_max_guests));
        $day = max(1, min((int) ($perDay ?: $row->claimed_per_day), $row->claimed_per_day));
        $row->update(['status' => 'verified', 'max_guests' => $max, 'per_day' => $day, 'raise_pending' => false, 'reason' => $reason ?: null, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        Notifier::notify($s->owner_id, 'supplier', $t->name.' events verified ✓', 'You can now offer '.$t->name.' packages: '.$row->limitsLabel().'.'.($max < $row->claimed_max_guests || $day < $row->claimed_per_day ? ' This is lower than you asked; you can ask again after more completed events.' : ''), route('business.packages', [], false));
        Notifier::audit('Verified event type', $s->name.' · '.$t->name.' · '.$row->limitsLabel(), $by);

        return ['ok' => true];
    }

    /** Pause one type (complaints, cancellations) or resume it; its packages hide while paused. */
    public static function setPaused(SupplierEventType $row, User $by, bool $pause, ?string $reason): array
    {
        if ($pause && ! trim((string) $reason)) {
            return self::fail('Say why the type is paused.');
        }
        if ($pause === ($row->status === 'paused')) {
            return self::fail($pause ? 'It is already paused.' : 'It is not paused.');
        }
        if (! $pause && $row->max_guests === null) {
            return self::fail('This type was never verified: review it instead.');
        }
        $row->update(['status' => $pause ? 'paused' : 'verified', 'reason' => $pause ? $reason : null, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        Notifier::notify($row->supplier->owner_id, 'supplier', $row->type->name.' events '.($pause ? 'paused' : 'resumed'), $pause ? 'PartyRent paused new '.$row->type->name.' bookings: '.$reason.' Your other event types are not affected. Bookings you already have stay.' : 'Customers can book your '.$row->type->name.' packages again.', route('business.event-types', [], false));
        Notifier::audit($pause ? 'Paused event type' : 'Resumed event type', $row->supplier->name.' · '.$row->type->name.($pause ? ' — '.$reason : ''), $by);

        return ['ok' => true];
    }

    /** A requested type: add it to the catalog (with the details PartyRent sets) or turn it down. */
    public static function decideRequest(EventType $t, User $by, bool $add, ?string $reason): array
    {
        if ($t->status !== 'requested') {
            return self::fail('That request was already handled.');
        }
        if ($add) {
            $t->update(['status' => 'active', 'sort' => 500]);
        } else {
            $t->update(['status' => 'hidden']);
        }
        if ($t->requested_by && ($s = Supplier::find($t->requested_by))) {
            Notifier::notify($s->owner_id, 'supplier', $add ? $t->name.' added to event types' : 'Event type not added: '.$t->name, $add ? 'You can now apply to offer '.$t->name.' events.' : ($reason ?: 'PartyRent did not add it.').' You can apply for one of the listed types instead.', route('business.event-types', [], false));
        }
        Notifier::audit($add ? 'Added event type' : 'Declined event type request', $t->name, $by);

        return ['ok' => true];
    }
}
