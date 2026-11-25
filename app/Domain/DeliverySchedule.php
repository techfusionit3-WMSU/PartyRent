<?php

namespace App\Domain;

use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Delivery details of each location of a rental, worked out on the server (never taken from the browser):
 *
 * - Deliver by (standard) = the earliest rental start time of the items going to that location minus the LONGEST
 *   allocated setup time among them (items: products.setup_minutes; a package: its own setup time, else the longest
 *   of its items — never added up). Multi-day rentals: the first rental date.
 * - Earlier delivery: the customer may ask for an earlier time. The fee is the shop's early-delivery rate per started
 *   hour earlier (delivery.earlyPerHour). It stays a REQUEST (status pending): not in the price, not the schedule,
 *   until the shop accepts it. Accepted: the time and the fee become part of the rental (total and balance updated).
 *   Rejected: the standard time stays and nothing is charged. The customer is told either way.
 * - Location names are made from the address (building / street and barangay), never typed, and kept unique.
 * - Several locations: each one after the first must be in the same delivery zone as the first, and must have an
 *   on-site contact name and number (someone else may receive the items there).
 */
class DeliverySchedule
{
    public const DEFAULT_EARLY_PER_HOUR = 100;

    /** "HH:MM" → minutes, or null. */
    public static function min(?string $t): ?int
    {
        return is_string($t) && preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    public static function hhmm(int $n): string
    {
        $n = max(0, $n);

        return sprintf('%02d:%02d', intdiv($n, 60) % 24, $n % 60);
    }

    /** Setup minutes of one rental line (item, or package: its own value else its longest item; never summed). */
    public static function setupOf(array $line): int
    {
        if (($line['type'] ?? 'item') === 'package') {
            $k = inv()->package((int) ($line['ref_id'] ?? 0));
            if ($k && $k->setup_minutes !== null) {
                return (int) $k->setup_minutes;
            }
            $max = 0;
            foreach ($k ? Packages::def($k)['components'] ?? [] : [] as $c) {
                if (! empty($c['productId']) && ($p = inv()->product((int) $c['productId']))) {
                    $max = max($max, (int) $p->setup_minutes);
                }
            }

            return $max;
        }

        return (int) (inv()->product((int) ($line['ref_id'] ?? 0))?->setup_minutes ?? 0);
    }

    /** The standard deliver-by time ("HH:MM") for a location, from the lines going there, or null when unknown. */
    public static function deliverBy(array $lines, string $destId, ?Supplier $s = null): ?string
    {
        $mine = array_values(array_filter($lines, fn ($l) => ($l['destId'] ?? null) === $destId));
        if (! $mine) {
            return null;
        }
        $first = collect($mine)->flatMap(fn ($l) => array_keys(array_filter($l['days'] ?? [])))->min();
        $best = null;
        foreach ($mine as $l) {
            if (! isset($l['days'][$first]) || ! $l['days'][$first]) {
                continue;
            }
            $t = Slots::time($l);
            $start = self::min($t['start'] ?? null) ?? self::min(Slots::settings($s)['open'] ?? null) ?? 8 * 60;
            $at = $start - self::setupOf($l);
            $best = $best === null ? $at : min($best, $at);
        }

        return $best === null ? null : self::hhmm($best);
    }

    /** The shop's fee for delivering earlier than the standard time: per started hour earlier. */
    public static function earlyFee(?Supplier $s, string $standard, string $requested): float
    {
        $mins = (self::min($standard) ?? 0) - (self::min($requested) ?? 0);
        if ($mins <= 0) {
            return 0.0;
        }
        $rate = (float) (((array) ($s?->delivery ?? []))['earlyPerHour'] ?? self::DEFAULT_EARLY_PER_HOUR);

        return r2((int) ceil($mins / 60) * $rate);
    }

    /** A recognizable, unique name for each location from its address ("Pilar Building, Brgy. Zone IV"). */
    public static function labels(array $destinations): array
    {
        $seen = [];
        foreach ($destinations as $i => &$d) {
            $street = trim((string) ($d['addr']['street'] ?? ''));
            $first = $street !== '' ? trim(explode(',', $street)[0]) : trim(explode(',', (string) ($d['address'] ?? ''))[0]);
            $brgy = trim((string) ($d['addr']['barangay'] ?? ''));
            $label = trim($first.($brgy !== '' ? ', Brgy. '.preg_replace('/^(brgy\.?|barangay)\s+/i', '', $brgy) : ''), ', ') ?: 'Location '.($i + 1);
            $label = mb_substr($label, 0, 80);
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                $seen[$key]++;
                $label .= ' ('.$seen[$key].')';
            } else {
                $seen[$key] = 1;
            }
            $d['label'] = $label;
        }
        unset($d);

        return $destinations;
    }

    /**
     * Deliver-by times, earlier-delivery requests, location names and the several-locations rules for a new order.
     * Returns ['ok' => true, 'destinations' => [...]] or ['ok' => false, 'error' => ...].
     */
    public static function prepare(Supplier $s, array $destinations, array $lines, array $raw): array
    {
        $destinations = self::labels($destinations);
        $rawById = collect($raw)->keyBy(fn ($d) => (string) ($d['id'] ?? ''));
        foreach ($destinations as $i => &$d) {
            $std = self::deliverBy($lines, $d['id'], $s);
            $d['standardTime'] = $std;
            $d['deliveryTime'] = $std ?? (self::min($d['deliveryTime'] ?? null) !== null ? $d['deliveryTime'] : null);
            unset($d['early']);
            $want = (string) ($rawById[$d['id']]['earlyTime'] ?? '');
            if ($want !== '' && $std !== null) {
                if (self::min($want) === null) {
                    return ['ok' => false, 'error' => 'Enter the earlier delivery time for "'.$d['label'].'" like 6:30 AM.'];
                }
                if (self::min($want) >= self::min($std)) {
                    return ['ok' => false, 'error' => 'The earlier delivery time for "'.$d['label'].'" must be before '.Slots::fmt12($std).' (the standard delivery time).'];
                }
                $d['early'] = ['time' => self::hhmm(self::min($want)), 'fee' => self::earlyFee($s, $std, $want), 'status' => 'pending', 'requestedAt' => now()->toIso8601String()];
            }
            if ($i > 0) {
                // a second person may receive the items here: their name and number are required
                $phone = preg_replace('/[\s\-()]/', '', (string) ($d['contactPhone'] ?? ''));
                if (mb_strlen(trim((string) ($d['contactName'] ?? ''))) < 2) {
                    return ['ok' => false, 'error' => 'Add the on-site contact name for "'.$d['label'].'".'];
                }
                if (! preg_match('/^(\+?63|0)\d{9,10}$/', $phone)) {
                    return ['ok' => false, 'error' => 'Add a valid on-site contact number for "'.$d['label'].'" (e.g. 0917 555 0101).'];
                }
            }
        }
        unset($d);
        // several locations: all in the delivery zone of the first one (zone-priced shops)
        if (count($destinations) > 1 && Pricing::zones($s) && (((array) $s->delivery)['mode'] ?? 'zones') !== 'distance') {
            $z0 = $destinations[0]['zoneId'] ?? '';
            foreach (array_slice($destinations, 1) as $d) {
                if (($d['zoneId'] ?? '') !== $z0) {
                    return ['ok' => false, 'error' => '"'.$d['label'].'" is in '.Pricing::zoneName($s, $d['zoneId'] ?? null).', outside the delivery zone of your first location ('
                        .Pricing::zoneName($s, $z0).'). With several locations, every location must be in the same delivery zone. Remove it or deliver it in a separate request.'];
                }
            }
        }

        return ['ok' => true, 'destinations' => $destinations];
    }

    /** Earlier-delivery fees the shop accepted (part of the price). */
    public static function acceptedFees(array $destinations): float
    {
        return r2(array_sum(array_map(fn ($d) => ($d['early']['status'] ?? '') === 'accepted' ? (float) $d['early']['fee'] : 0, $destinations)));
    }

    /** The shop accepts or rejects a customer's earlier-delivery request for one location. */
    public static function decide(RentalRequest $r, string $destId, bool $accept, User $by): array
    {
        return DB::transaction(function () use ($r, $destId, $accept, $by) {
            $r = RentalRequest::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if (in_array($r->status, ['declined', 'cancelled', 'completed', 'ongoing', 'return_pending', 'inspection'], true)) {
                return ['ok' => false, 'error' => 'This rental can no longer change its delivery time.'];
            }
            $dests = $r->destinations ?? [];
            $i = collect($dests)->search(fn ($d) => ($d['id'] ?? null) === $destId);
            if ($i === false || ($dests[$i]['early']['status'] ?? '') !== 'pending') {
                return ['ok' => false, 'error' => 'There is no earlier-delivery request waiting here.'];
            }
            $e = $dests[$i]['early'];
            $dests[$i]['early'] = array_merge($e, ['status' => $accept ? 'accepted' : 'rejected', 'decidedAt' => now()->toIso8601String(), 'decidedBy' => $by->name]);
            $p = (array) $r->pricing;
            $label = $dests[$i]['label'] ?? 'your location';
            if ($accept) {
                $dests[$i]['deliveryTime'] = $e['time'];
                $oldBalance = (float) ($p['finalBalance'] ?? 0);
                $p['earlyDeliveryFee'] = r2((float) ($p['earlyDeliveryFee'] ?? 0) + (float) $e['fee']);
                $p['total'] = r2((float) $p['total'] + (float) $e['fee']);
                $p = self::restage($p);
                $extra = $r->plan === 'installment' ? ['installment_plan' => self::rescale($r->installment_plan, $oldBalance, (float) $p['finalBalance'])] : [];
                $r->update(['destinations' => $dests, 'pricing' => $p] + $extra);
            } else {
                $r->update(['destinations' => $dests]);
            }
            $r->finCache = null;
            $what = 'Earlier delivery to '.$label.' at '.Slots::fmt12($e['time']).($accept ? ' accepted (+'.money($e['fee']).')' : ' not possible; delivery stays at '.Slots::fmt12($dests[$i]['standardTime'] ?? $dests[$i]['deliveryTime'] ?? '').', no extra fee');
            Rentals::history($r, $r->status, $what);
            Notifier::audit($accept ? 'Accepted earlier delivery' : 'Rejected earlier delivery', $r->code.' · '.$what, $by);
            Notifier::notify($r->customer, 'customer', $accept ? 'Earlier delivery confirmed for '.$r->code : 'Earlier delivery not possible for '.$r->code,
                $r->supplier->name.': '.$what.'.'.($accept ? ' Your total is now '.money($p['total']).'.' : ''), route('rentals.show', $r, false));

            return ['ok' => true];
        });
    }

    private static function restage(array $p): array
    {
        $t = $p['terms'] ?? [];
        $plan = $p['plan'] ?? 'full';
        // the down payment keeps its amount once a rental is booked; the extra fee goes to the balance
        $p['finalBalance'] = r2(max(0, $p['total'] - ($p['reservationFee'] ?? 0) - (float) ($p['downPayment'] ?? 0)));
        if ($plan === 'full') {
            $p['downPayment'] = 0.0;
            $p['finalBalance'] = r2($p['total']);
        }
        $p['payable'] = $p['total'];

        return $p;
    }

    private static function rescale(?array $ip, float $old, float $new): ?array
    {
        if (! $ip || empty($ip['items']) || $old <= 0) {
            return $ip;
        }
        $items = $ip['items'];
        $sum = 0.0;
        foreach ($items as $k => $it) {
            if ($k < count($items) - 1) {
                $items[$k]['amount'] = r2($it['amount'] * $new / $old);
                $sum += $items[$k]['amount'];
            }
        }
        $items[count($items) - 1]['amount'] = r2(max(0, $new - $sum));

        return array_merge($ip, ['items' => $items]);
    }
}
