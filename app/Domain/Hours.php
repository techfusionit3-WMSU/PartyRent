<?php

namespace App\Domain;

use App\Models\Product;

/**
 * Times for items rented by the hour. A line's `time` is {from: "HH:MM", to: "HH:MM"} on its one date;
 * "24:00" means midnight at the end of that day. Minutes since midnight are used for overlap checks.
 */
class Hours
{
    public static function toMin(?string $hhmm): ?int
    {
        if (! is_string($hhmm) || ! preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $hhmm, $m)) {
            return null;
        }
        $min = (int) $m[1] * 60 + (int) $m[2];

        return $min <= 1440 ? $min : null;
    }

    public static function fmt(int $min): string
    {
        $h = intdiv($min, 60) % 24;
        $m = $min % 60;

        return ($h % 12 ?: 12).':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT).' '.($h < 12 ? 'AM' : 'PM');
    }

    /** [fromMin, toMin] of a valid time window, else null. */
    public static function window(?array $time): ?array
    {
        $f = self::toMin($time['from'] ?? null);
        $t = self::toMin($time['to'] ?? null);

        return $f !== null && $t !== null && $t > $f ? [$f, $t] : null;
    }

    public static function length(?array $time): float
    {
        $w = self::window($time);

        return $w ? round(($w[1] - $w[0]) / 60, 2) : 0.0;
    }

    /** "2:00 PM – 6:00 PM (4 h)" */
    public static function label(?array $time): string
    {
        $w = self::window($time);
        if (! $w) {
            return '';
        }
        $h = self::length($time);

        return self::fmt($w[0]).' – '.self::fmt($w[1]).' ('.rtrim(rtrim(number_format($h, 2), '0'), '.').' h)';
    }

    /** The time window of a line for an hourly item, or null for every other line. */
    public static function lineWindow(array $line): ?array
    {
        if (($line['type'] ?? 'item') !== 'item' || empty($line['time'])) {
            return null;
        }
        $p = inv()->product($line['ref_id']);

        return $p && $p->isHourly() ? self::window($line['time']) : null;
    }

    /** Problems with an hourly booking (null = fine). */
    public static function error(Product $p, array $days, ?array $time): ?string
    {
        $w = self::window($time);
        if (! $w) {
            return 'Choose a start and end time (the end must be after the start).';
        }
        if (count($days) !== 1) {
            return $p->name.' is rented by the hour: choose one date per booking.';
        }
        $hours = ($w[1] - $w[0]) / 60;
        if ($hours + 1e-9 < (float) $p->min_hours) {
            return $p->name.' needs at least '.rtrim(rtrim(number_format((float) $p->min_hours, 1), '0'), '.').' hours.';
        }
        $date = array_key_first($days);
        if ($date === today_iso() && $w[0] < (int) now()->format('G') * 60 + (int) now()->format('i')) {
            return 'That start time has already passed today.';
        }

        return null;
    }

    /** Highest number of units in use at the same moment among [from, to, qty] intervals. */
    public static function peak(array $slots): int
    {
        $ev = [];
        foreach ($slots as [$f, $t, $q]) {
            $ev[] = [$f, $q];
            $ev[] = [$t, -$q];
        }
        // ends before starts at the same minute: back-to-back bookings do not overlap
        usort($ev, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $cur = $max = 0;
        foreach ($ev as [, $d]) {
            $cur += $d;
            $max = max($max, $cur);
        }

        return $max;
    }
}
