<?php

namespace App\Domain;

use App\Models\EventBooking;
use App\Models\EventBookingPart;
use App\Models\EventTeam;
use App\Models\ShopStaff;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Event company roles (2026-11-22): Event Coordinator (Front Desk role), Finance (Cashier role), Event Crew (Field
 * Crew role), each with its own module. A booking can be assigned to one coordinator; crew see the parts their teams
 * work. Notifications go to the role that acts on them (and always to the owner):
 *   new inquiry → coordinators · payment to confirm → finance · booking assigned → its coordinator ·
 *   team assigned → that team's members.
 */
class EventRoles
{
    /** Active members of the company with a role (not the owner). */
    public static function withRole(Supplier $s, string $role): Collection
    {
        return ShopStaff::where('supplier_id', $s->id)->withRole($role)->where('status', 'active')->with('user')->get()->pluck('user')->filter()->values();
    }

    /** People who can coordinate an event: the owner and the coordinators. */
    public static function coordinators(Supplier $s): Collection
    {
        return collect([$s->owner])->concat(self::withRole($s, 'desk'))->filter()->unique('id')->values();
    }

    /** Send to the owner and to every member with the role. */
    public static function notifyRole(Supplier $s, string $role, string $title, string $body, string $link): void
    {
        Notifier::notify($s->owner_id, 'supplier', $title, $body, $link);
        foreach (self::withRole($s, $role) as $u) {
            Notifier::notify($u->id, 'supplier', $title, $body, $link);
        }
    }

    /** Send to the owner and the booking's coordinator. */
    public static function notifyBooking(EventBooking $b, string $title, string $body, string $link): void
    {
        Notifier::notify($b->supplier->owner_id, 'supplier', $title, $body, $link);
        if ($b->coordinator_id && $b->coordinator_id !== $b->supplier->owner_id) {
            Notifier::notify($b->coordinator_id, 'supplier', $title, $body, $link);
        }
    }

    public static function setCoordinator(EventBooking $b, ?int $userId, User $by): array
    {
        if ($userId && ! self::coordinators($b->supplier)->contains('id', $userId)) {
            return ['ok' => false, 'error' => 'Choose the owner or a team member with the Event Coordinator role.'];
        }
        $b->update(['coordinator_id' => $userId ?: null]);
        if ($userId && $userId !== $by->id) {
            Notifier::notify($userId, 'supplier', 'You coordinate '.$b->code, $b->event_type.' for '.$b->customer->name.' ('.$b->whenLabel().'). It is on your desk.', route('business.bookings.show', $b, false));
        }
        Notifier::audit('Assigned coordinator', $b->code.' → '.($userId ? User::find($userId)?->name : 'nobody'), $by);

        return ['ok' => true];
    }

    /** The parts the user's teams work (booked / confirmed bookings), from 3 days ago on. */
    public static function crewParts(Supplier $s, User $u): Collection
    {
        $teams = EventTeam::where('supplier_id', $s->id)->get()->filter(fn ($t) => in_array($u->id, (array) $t->members, true) || $s->owner_id === $u->id)->pluck('id');

        return EventBookingPart::whereIn('event_team_id', $teams)->whereDate('date', '>=', today()->subDays(3))
            ->whereHas('booking', fn ($q) => $q->where('supplier_id', $s->id)->whereIn('status', ['booked', 'confirmed']))
            ->with(['booking.customer', 'booking.coordinator'])->orderBy('date')->orderBy('start_time')->get()
            ->reject(fn ($p) => $p->done_at && $p->date->isBefore(today()));
    }

    /** Tell a team's members they work a part. */
    public static function notifyTeam(EventBookingPart $p): void
    {
        $t = $p->event_team_id ? EventTeam::find($p->event_team_id) : null;
        foreach ((array) ($t?->members ?? []) as $uid) {
            Notifier::notify($uid, 'supplier', $t->name.' works '.$p->booking->code.' · '.$p->date->format('M j'), $p->name.' of '.$p->booking->event_type.' ('.$p->booking->customer->name.') on '.fmt_date($p->date->toDateString()).($p->timeLabel() ? ', '.$p->timeLabel() : '').'.', route('business.crew', [], false));
        }
    }
}
