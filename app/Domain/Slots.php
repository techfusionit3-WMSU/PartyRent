<?php

namespace App\Domain;

use App\Models\Package;
use App\Models\Product;
use App\Models\Supplier;

/**
 * Rental TIME handling (port of reference js/core/slots.js): start/end times (12-hour display), supplier
 * rental hours, preparation & recovery buffers, automatic duration and the overlap ("peak") calculation
 * used by Inventory for time-based stock.
 *
 * A line's time {start:'HH:MM', end:'HH:MM'} means: the rental starts at `start` on its first date and
 * ends at `end` on its last date (days in between are full days). A line without a time is all day.
 */
class Slots
{
    public const DAY = 1440;

    public const DEFAULT = ['open' => '06:00', 'close' => '22:00', 'prepHours' => 1, 'recoveryHours' => 1, 'step' => 30];

    public static function toMin(?string $t): ?int
    {
        if ($t === null || $t === '' || ! preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function fromMin(int $n): string
    {
        return str_pad((string) intdiv($n, 60), 2, '0', STR_PAD_LEFT).':'.str_pad((string) ($n % 60), 2, '0', STR_PAD_LEFT);
    }

    /** "6:00 AM" */
    public static function fmt12(string|int|null $t): string
    {
        $n = is_int($t) ? $t : self::toMin($t);
        if ($n === null) {
            return '';
        }
        if ($n >= self::DAY) {
            return '12:00 AM (midnight)';
        }
        $h = intdiv($n, 60);

        return (($h % 12) ?: 12).':'.str_pad((string) ($n % 60), 2, '0', STR_PAD_LEFT).' '.($h < 12 ? 'AM' : 'PM');
    }

    public static function rangeLabel(?array $time): string
    {
        return $time && ! empty($time['start']) && ! empty($time['end']) ? self::fmt12($time['start']).' – '.self::fmt12($time['end']) : 'All day';
    }

    /** Supplier rental hours and turnaround buffers (with defaults). */
    public static function settings(?Supplier $s): array
    {
        return array_merge(self::DEFAULT, $s?->time_settings ?? []);
    }

    /** Selectable times (every 30 minutes) within the supplier's rental hours. */
    public static function options(?Supplier $s): array
    {
        $st = self::settings($s);
        $out = [];
        for ($n = self::toMin($st['open']); $n <= self::toMin($st['close']); $n += (int) ($st['step'] ?: 30)) {
            $out[] = self::fromMin($n);
        }

        return $out;
    }

    /**
     * Default time for a new booking: the item's own time frame when it has one (change list C7), else the
     * supplier's full rental hours.
     */
    public static function defaultTime(?Supplier $s, Product|Package|null $item = null): array
    {
        if ($f = self::frame($item)) {
            return ['start' => $f['start'], 'end' => $f['end']];
        }
        $st = self::settings($s);

        return ['start' => $st['open'], 'end' => $st['close']];
    }

    /**
     * An item's own rental time frame (change list C7): ['start', 'end', 'overnight'] or null when the item
     * follows the shop's rental hours. A frame whose end is not after its start runs past midnight
     * (only allowed with overnight rental).
     */
    public static function frame(Product|Package|null $item): ?array
    {
        if (! $item instanceof Product || ! $item->time_from || ! $item->time_to) {
            return null;
        }

        return ['start' => $item->time_from, 'end' => $item->time_to, 'overnight' => (bool) $item->allow_overnight];
    }

    /** "8:00 AM – 5:00 PM", "+ overnight" when the item may be kept past midnight. */
    public static function frameLabel(Product|Package|null $item): string
    {
        $f = self::frame($item);

        return $f ? self::fmt12($f['start']).' – '.self::fmt12($f['end']).($f['overnight'] ? ' · overnight rental allowed' : ' · same day only') : '';
    }

    /** Is minute-of-day $n inside the frame? A frame ending at or before its start wraps past midnight. */
    private static function inFrame(int $n, int $from, int $to): bool
    {
        return $to > $from ? $n >= $from && $n <= $to : $n >= $from || $n <= $to;
    }

    /** A line's time as ['start' => 'HH:MM', 'end' => 'HH:MM'] or null (all day). Accepts the older {from, to} shape. */
    public static function time(array $line): ?array
    {
        $t = $line['time'] ?? null;
        if (! is_array($t)) {
            return null;
        }
        $a = $t['start'] ?? $t['from'] ?? null;
        $b = $t['end'] ?? $t['to'] ?? null;

        return $a && $b ? ['start' => $a, 'end' => $b] : null;
    }

    public static function dayNum(string $d): int
    {
        return intdiv((int) strtotime(substr($d, 0, 10).' 00:00:00 UTC'), 86400);
    }

    /**
     * Occupied intervals of one line, one per rental date, in absolute minutes. $buf = [prep, rec] minutes:
     * preparation before the start of a run of dates and recovery after its end. All-day bookings already
     * occupy the whole day, so buffers only apply to timed bookings.
     *
     * @return array<int, array{date:string, qty:int, from:int, to:int, rawFrom:int, rawTo:int}>
     */
    public static function lineIntervals(array $line, array $buf = [0, 0]): array
    {
        $dates = array_keys(array_filter($line['days'] ?? [], fn ($q) => (int) $q > 0));
        sort($dates);
        $set = array_flip($dates);
        $t = self::time($line);
        $out = [];
        foreach ($dates as $d) {
            $runStart = ! isset($set[add_days($d, -1)]);
            $runEnd = ! isset($set[add_days($d, 1)]);
            $base = self::dayNum($d) * self::DAY;
            $f = $runStart && $t ? self::toMin($t['start']) : 0;
            $e = $runEnd && $t ? self::toMin($t['end']) : self::DAY;
            if ($runStart && $runEnd && $t && $e <= $f) {
                $e = $f; // invalid same-day range — reported by validateLine
            }
            $out[] = [
                'date' => $d, 'qty' => (int) $line['days'][$d], 'rawFrom' => $base + $f, 'rawTo' => $base + $e,
                'from' => $base + $f - ($runStart && $t ? $buf[0] : 0), 'to' => $base + $e + ($runEnd && $t ? $buf[1] : 0),
            ];
        }

        return $out;
    }

    /** Highest combined quantity of overlapping intervals inside [A, B). Half-open: touching intervals do not overlap. */
    public static function peak(array $intervals, int $A, int $B): int
    {
        $ev = [];
        foreach ($intervals as $iv) {
            $f = max($iv['from'], $A);
            $t = min($iv['to'], $B);
            if ($f < $t && $iv['qty']) {
                $ev[] = [$f, $iv['qty']];
                $ev[] = [$t, -$iv['qty']];
            }
        }
        usort($ev, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]); // ends before starts at the same minute
        $cur = 0;
        $max = 0;
        foreach ($ev as $e) {
            $cur += $e[1];
            $max = max($max, $cur);
        }

        return $max;
    }

    /** Total rental duration from the first date's start time to the last date's end time. */
    public static function duration(array $line): array
    {
        $dates = array_keys(array_filter($line['days'] ?? [], fn ($q) => (int) $q > 0));
        sort($dates);
        if (! $dates) {
            return ['minutes' => 0, 'hours' => 0, 'label' => '—'];
        }
        $t = self::time($line) ?? ['start' => '00:00', 'end' => '24:00'];
        $mins = (self::dayNum(end($dates)) - self::dayNum($dates[0])) * self::DAY + self::toMin($t['end']) - self::toMin($t['start']);

        return ['minutes' => $mins, 'hours' => $mins / 60, 'label' => self::durationLabel($mins, self::time($line) === null)];
    }

    public static function durationLabel(int $mins, bool $allDay = false): string
    {
        if ($allDay) {
            $d = (int) round($mins / self::DAY);

            return $d.' Day'.($d === 1 ? '' : 's').' (all day)';
        }
        if ($mins <= 0) {
            return '0 Hours';
        }
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        $base = $h.' Hour'.($h === 1 ? '' : 's').($m ? ' '.$m.' Min' : '');
        if ($h < 24) {
            return $base;
        }
        $d = intdiv($h, 24);
        $rh = $h % 24;

        return $base.' ('.$d.' day'.($d > 1 ? 's' : '').($rh ? ', '.$rh.' hour'.($rh > 1 ? 's' : '') : '').($m ? ', '.$m.' min' : '').')';
    }

    /** Time issues of a line: rental hours, end after start, min/max rental hours. */
    public static function validateLine(array $line): array
    {
        $item = ($line['type'] ?? 'item') === 'package' ? Package::find($line['ref_id']) : Product::find($line['ref_id']);
        $time = self::time($line);
        if (! $item || ! $time) {
            return [];
        }
        $out = [];
        $name = $item->name;
        $st = self::settings($item->supplier);
        $a = self::toMin($time['start']);
        $b = self::toMin($time['end']);
        if ($a === null || $b === null) {
            return ['Select a start time and an end time for '.$name.'.'];
        }
        $open = self::toMin($st['open']);
        $close = self::toMin($st['close']);
        if ($a < $open || $a > $close || $b < $open || $b > $close) {
            $out[] = $name.': rental times must be within the supplier\'s rental hours ('.self::fmt12($st['open']).' – '.self::fmt12($st['close']).').';
        }
        $dates = array_keys(array_filter($line['days'] ?? [], fn ($q) => (int) $q > 0));
        if (! $dates) {
            return $out;
        }
        $set = array_flip($dates);
        if ($f = self::frame($item)) { // the item's own time frame (C7)
            $ff = self::toMin($f['start']);
            $ft = self::toMin($f['end']);
            if (! self::inFrame($a, $ff, $ft) || ! self::inFrame($b, $ff, $ft)) {
                $out[] = $name.' can only be rented between '.self::fmt12($f['start']).' and '.self::fmt12($f['end']).'.';
            }
            $multi = array_filter($dates, fn ($d) => isset($set[add_days($d, 1)]));
            if (! $f['overnight'] && $multi) {
                $out[] = $name.' cannot be kept overnight. Book it for one date at a time.';
            }
        }
        $single = array_filter($dates, fn ($d) => ! isset($set[add_days($d, -1)]) && ! isset($set[add_days($d, 1)]));
        if ($single && $b <= $a) {
            $out[] = $name.': the end time must be later than the start time. For an overnight rental, set the end date to the next day.';
        }
        $dur = self::duration($line);
        $min = (float) ($item->min_hours ?? 0);
        $max = (float) ($item->max_hours ?? 0);
        if ($min > 0 && $dur['hours'] < $min) {
            $out[] = $name.' has a minimum rental duration of '.self::num($min).' hour'.($min > 1 ? 's' : '').'. Your selected time is '.self::durationLabel($dur['minutes']).'.';
        }
        if ($max > 0 && $dur['hours'] > $max) {
            $out[] = $name.' has a maximum rental duration of '.self::num($max).' hours per booking. Your selected time is '.self::durationLabel($dur['minutes']).'.';
        }

        return $out;
    }

    public static function timeIssues(array $lines): array
    {
        return array_merge([], ...array_map([self::class, 'validateLine'], $lines));
    }

    public static function hourLimitLabel(Product|Package|null $item): string
    {
        $a = (float) ($item?->min_hours ?? 0);
        $b = (float) ($item?->max_hours ?? 0);
        if ($a && $b) {
            return self::num($a).'–'.self::num($b).' hours per booking';
        }
        if ($a) {
            return 'Minimum '.self::num($a).' hour'.($a > 1 ? 's' : '');
        }

        return $b ? 'Maximum '.self::num($b).' hours per booking' : '';
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
