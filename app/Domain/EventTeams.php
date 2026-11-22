<?php

namespace App\Domain;

use App\Models\EventBooking;
use App\Models\EventBookingPart;
use App\Models\EventTeam;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Teams decide how many events an event company can run at the same time (2026-11-22).
 *
 * A team works one event part at a time and is busy from (start − setup hours) to (end + teardown hours); a part
 * without times takes the team for the whole day. A time is free when some team has nothing overlapping it, beyond
 * the parts still waiting for a team. Confirmed bookings get a free team on every part automatically; the owner can
 * move a part to another team that is free. A business without teams keeps its "events a day" setting
 * (Events::dateFree). The verified per-type limits (EventTypes) apply on top.
 */
class EventTeams
{
    public static function teams(Supplier $s): Collection
    {
        return EventTeam::where('supplier_id', $s->id)->where('active', true)->orderBy('id')->get();
    }

    public static function uses(Supplier $s): bool
    {
        return EventTeam::where('supplier_id', $s->id)->where('active', true)->exists();
    }

    private static function min(?string $t): ?int
    {
        return $t && preg_match('/^(\d{2}):(\d{2})$/', $t, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    /** Minutes of the day the team is busy for a part: setup before, teardown after; no times = all day. */
    public static function window(Supplier $s, ?string $start, ?string $end): array
    {
        $a = self::min($start);
        $b = self::min($end);
        if ($a === null) {
            return [0, 1440];
        }
        $set = $s->bizSettings();
        $b ??= min(1440, $a + 240); // a start without an end: count 4 hours

        return [max(0, $a - (int) round((float) $set['setupHours'] * 60)), min(1440, $b + (int) round((float) $set['teardownHours'] * 60))];
    }

    private static function overlap(array $x, array $y): bool
    {
        return $x[0] < $y[1] && $y[0] < $x[1];
    }

    /** Parts of held bookings (booked / confirmed) on that date, with their busy window. */
    private static function busy(Supplier $s, string $date, ?int $exceptBooking = null, ?int $exceptPart = null): Collection
    {
        return EventBookingPart::whereDate('date', $date)
            ->whereHas('booking', fn ($q) => $q->where('supplier_id', $s->id)->whereIn('status', ['booked', 'confirmed'])->when($exceptBooking, fn ($w) => $w->where('id', '!=', $exceptBooking)))
            ->when($exceptPart, fn ($q) => $q->where('id', '!=', $exceptPart))
            ->get()->map(fn ($p) => ['team' => $p->event_team_id, 'w' => self::window($s, $p->start_time, $p->end_time), 'id' => $p->id]);
    }

    /** Teams with nothing overlapping that time, given extra parts already taken in this same check. */
    private static function freeTeams(Supplier $s, string $date, array $w, ?int $exceptBooking, ?int $exceptPart = null, array $taken = []): Collection
    {
        $busy = self::busy($s, $date, $exceptBooking, $exceptPart)->concat($taken);

        return self::teams($s)->filter(fn ($t) => ! $busy->contains(fn ($p) => $p['team'] === $t->id && self::overlap($p['w'], $w)));
    }

    /** Is a team free at that time on that date (after the parts still waiting for a team)? */
    public static function isFree(Supplier $s, string $date, ?string $start, ?string $end, ?int $exceptBooking = null): bool
    {
        $w = self::window($s, $start, $end);
        $waiting = self::busy($s, $date, $exceptBooking)->filter(fn ($p) => $p['team'] === null && self::overlap($p['w'], $w))->count();

        return self::freeTeams($s, $date, $w, $exceptBooking)->count() > $waiting;
    }

    /** Give every part of a confirmed booking without a team a free one (first free team). Returns parts left without. */
    public static function assign(EventBooking $b): int
    {
        $s = $b->supplier;
        if (! self::uses($s)) {
            return 0;
        }
        $left = 0;
        $taken = [];
        foreach ($b->parts()->get() as $p) {
            $w = self::window($s, $p->start_time, $p->end_time);
            if ($p->event_team_id) {
                $taken[] = ['team' => $p->event_team_id, 'w' => $w, 'id' => $p->id];

                continue;
            }
            $t = self::freeTeams($s, $p->date->toDateString(), $w, $b->id, null, array_values(array_filter($taken, fn ($x) => true)))->first();
            if ($t) {
                $p->update(['event_team_id' => $t->id]);
                $taken[] = ['team' => $t->id, 'w' => $w, 'id' => $p->id];
            } else {
                $left++;
            }
        }
        if ($left) {
            Notifier::notify($s->owner_id, 'supplier', 'No free team for '.$b->code, $left.' part(s) of '.$b->code.' could not get a team. Assign one on the booking page.', route('business.bookings.show', $b, false));
        }

        return $left;
    }

    /** Move a part to another team (or none). The team must be free at that time. */
    public static function setTeam(EventBookingPart $p, ?int $teamId, User $by): array
    {
        $b = $p->booking;
        $s = $b->supplier;
        if (! in_array($b->status, ['booked', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'Teams are set on booked or confirmed events.'];
        }
        if ($teamId) {
            $t = EventTeam::where('supplier_id', $s->id)->where('active', true)->find($teamId);
            if (! $t) {
                return ['ok' => false, 'error' => 'Choose one of your active teams.'];
            }
            $w = self::window($s, $p->start_time, $p->end_time);
            if (! self::freeTeams($s, $p->date->toDateString(), $w, null, $p->id)->contains('id', $t->id)) {
                return ['ok' => false, 'error' => $t->name.' already works another event at that time on '.fmt_date($p->date->toDateString()).'.'];
            }
        }
        $p->update(['event_team_id' => $teamId ?: null]);
        Notifier::audit('Assigned team', $b->code.' · '.$p->name.' → '.($teamId ? EventTeam::find($teamId)->name : 'no team'), $by);

        return ['ok' => true];
    }

    /** Upcoming parts each team works (for the teams page). */
    public static function schedule(Supplier $s): Collection
    {
        return EventBookingPart::whereDate('date', '>=', today())->whereNotNull('event_team_id')
            ->whereHas('booking', fn ($q) => $q->where('supplier_id', $s->id)->whereIn('status', ['booked', 'confirmed']))
            ->with('booking.customer')->orderBy('date')->orderBy('start_time')->get()->groupBy('event_team_id');
    }
}
