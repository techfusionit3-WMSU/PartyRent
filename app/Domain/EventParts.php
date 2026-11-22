<?php

namespace App\Domain;

use App\Models\EventBooking;
use App\Models\EventPackage;
use App\Models\EventType;
use App\Models\Supplier;

/**
 * Event parts (2026-11-22): an event can be one day or several dates / stages — a wedding's prenup shoot, ceremony
 * and reception; a 3-day fiesta; a wake's nights; a conference's setup day and its days. Generic for every type:
 * the parts come from the package (which starts from its event type's usual parts), or from the event type when
 * the customer asks for a custom event. Optional parts are chosen by the customer and may cost extra.
 *
 * Each part has its own date, time and venue. Every part's date must be free (the business's events a day, and the
 * event type's events a day); the booking's event_date is the first part, so payments are due before it starts.
 */
class EventParts
{
    public const MAX = 12;

    /** A package's parts when it is created: the event type's usual parts, else one part "Event". */
    public static function defaultFor(?string $typeName): array
    {
        $t = $typeName ? EventType::where('name', $typeName)->first() : null;
        $parts = array_map(fn ($p) => ['name' => $p['name'], 'optional' => (bool) ($p['optional'] ?? false), 'price' => 0], (array) ($t?->parts ?? []));

        return $parts ?: [['name' => 'Event', 'optional' => false, 'price' => 0]];
    }

    /** What the inquiry asks a date for: the package's parts, else the event type's usual ones, else one part. */
    public static function definitions(?EventPackage $k, ?string $typeName): array
    {
        $parts = $k && $k->parts ? $k->parts : self::defaultFor($typeName);

        return array_values(array_map(fn ($p) => ['name' => (string) $p['name'], 'optional' => (bool) ($p['optional'] ?? false), 'price' => (float) ($p['price'] ?? 0)], $parts));
    }

    /** Clean the package form's part rows: [{name, optional, price}], at least one required part. */
    public static function fromForm(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $opt = ! empty($r['optional']);
            $out[] = ['name' => mb_substr($name, 0, 60), 'optional' => $opt, 'price' => $opt ? round(max(0, (float) ($r['price'] ?? 0)), 2) : 0];
        }

        return array_slice($out, 0, self::MAX);
    }

    /**
     * The booking's parts from the inquiry: one row per definition (date, start, end, venue, include for optional).
     * Without part rows (older forms / API) the single event date and time are one part.
     *
     * @return array{ok: bool, error?: string, parts?: array}
     */
    public static function build(array $defs, array $input, array $v): array
    {
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (! $input) {
            return ['ok' => true, 'parts' => [['name' => $defs[0]['name'] ?? 'Event', 'date' => $v['event_date'], 'start_time' => $v['start_time'] ?? null, 'end_time' => $v['end_time'] ?? null, 'venue' => null, 'price' => 0, 'sort' => 0]]];
        }
        $out = [];
        foreach ($defs as $i => $d) {
            $row = (array) ($input[$i] ?? []);
            if ($d['optional'] && empty($row['include'])) {
                continue;
            }
            $date = (string) ($row['date'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
                return ['ok' => false, 'error' => 'Choose a date for '.$d['name'].'.'];
            }
            $st = ($row['start'] ?? '') !== '' ? (string) $row['start'] : null;
            $en = ($row['end'] ?? '') !== '' ? (string) $row['end'] : null;
            if (($st && ! preg_match($time, $st)) || ($en && ! preg_match($time, $en))) {
                return ['ok' => false, 'error' => 'Check the time of '.$d['name'].'.'];
            }
            if ($st && $en && $en <= $st) {
                return ['ok' => false, 'error' => $d['name'].': the end time must be after the start time.'];
            }
            $venue = trim((string) ($row['venue'] ?? ''));
            $out[] = ['name' => $d['name'], 'date' => $date, 'start_time' => $st, 'end_time' => $en, 'venue' => $venue !== '' ? mb_substr($venue, 0, 255) : null, 'price' => $d['optional'] ? $d['price'] : 0, 'sort' => $i];
        }
        if (! $out) {
            return ['ok' => false, 'error' => 'Choose at least one part of the event.'];
        }
        usort($out, fn ($a, $b) => [$a['date'], $a['sort']] <=> [$b['date'], $b['sort']]);

        return ['ok' => true, 'parts' => $out];
    }

    /** The different dates the parts fall on. */
    public static function dates(array $parts): array
    {
        $d = array_values(array_unique(array_map(fn ($p) => $p['date'] instanceof \DateTimeInterface ? $p['date']->format('Y-m-d') : (string) $p['date'], $parts)));
        sort($d);

        return $d;
    }

    /** Why these parts cannot be held (null = a team / slot is free for each, within the type's limits). */
    public static function capacityError(Supplier $s, string $type, int $guests, array $parts, ?int $except = null): ?string
    {
        foreach ($parts as $p) {
            $date = $p['date'] instanceof \DateTimeInterface ? $p['date']->format('Y-m-d') : (string) $p['date'];
            if (! Events::dateFree($s, $date, $except, $p['start_time'] ?? null, $p['end_time'] ?? null)) {
                return $s->name.' is fully booked on '.fmt_date($date).(! empty($p['start_time']) ? ' at that time' : '').'. Try another date or time for '.($p['name'] ?? 'that part').'.';
            }
        }
        foreach (self::dates($parts) as $date) {
            if ($e = EventTypes::inquiryError($s, $type, $guests, $date, $except)) {
                return $e;
            }
        }

        return null;
    }

    /** The booking's parts as plain rows (name, date, times). */
    public static function bookingParts(EventBooking $b): array
    {
        $rows = $b->parts()->get()->map(fn ($p) => ['name' => $p->name, 'date' => $p->date->toDateString(), 'start_time' => $p->start_time, 'end_time' => $p->end_time])->all();

        return $rows ?: [['name' => 'Event', 'date' => $b->event_date->toDateString(), 'start_time' => $b->start_time, 'end_time' => $b->end_time]];
    }

    /** Packages without parts get their type's usual parts; bookings without parts get one part on their date. */
    public static function backfill(Supplier $s): void
    {
        foreach ($s->eventPackages()->get() as $k) {
            if (! $k->parts) {
                $k->update(['parts' => self::defaultFor($k->events[0] ?? null)]);
            }
        }
        foreach ($s->eventBookings()->doesntHave('parts')->get() as $b) {
            $b->parts()->create(['name' => 'Event', 'date' => $b->event_date, 'start_time' => $b->start_time, 'end_time' => $b->end_time, 'sort' => 0,
                'done_at' => $b->status === 'completed' ? ($b->completed_at ?? now()) : null]);
        }
    }

    /** The booking's dates, from its saved parts. */
    public static function bookingDates(EventBooking $b): array
    {
        return self::dates($b->parts()->get()->map(fn ($p) => ['date' => $p->date])->all()) ?: [$b->event_date->toDateString()];
    }
}
