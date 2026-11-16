<?php

namespace App\Support;

/**
 * The customer's selected rental period (port of reference js/core/rental-period.js), kept in the session.
 * Shared by browsing, product / package pages, the Rental List and checkout. Cleared at login (the
 * post-login prompt asks again) and at logout.
 */
class RentalPeriod
{
    private const KEY = 'rental_period';

    public const ASK = 'ask_dates';

    /** Error message or null. */
    public static function validate(?string $start, ?string $end): ?string
    {
        if (! $start || ! $end) {
            return 'Select both a start date and an end date.';
        }
        if ($start < today_iso()) {
            return 'The start date cannot be in the past.';
        }
        if ($end < $start) {
            return 'The end date must be on or after the start date.';
        }
        $max = (int) setting('maxRentalDays', 30);
        if (diff_days($start, $end) + 1 > $max) {
            return 'Rental periods can be at most '.$max.' days.';
        }

        return null;
    }

    /** ['start' => …, 'end' => …] or null. Past periods are dropped. */
    public static function get(): ?array
    {
        $v = session(self::KEY);
        if (! $v || empty($v['start']) || empty($v['end'])) {
            return null;
        }
        if ($v['start'] < today_iso()) {
            self::clear();

            return null;
        }

        return $v;
    }

    public static function set(string $start, string $end): bool
    {
        if (self::validate($start, $end)) {
            return false;
        }
        session([self::KEY => ['start' => $start, 'end' => $end]]);

        return true;
    }

    public static function clear(): void
    {
        session()->forget(self::KEY);
    }

    public static function days(?array $p = null): int
    {
        $p ??= self::get();

        return $p ? diff_days($p['start'], $p['end']) + 1 : 0;
    }

    public static function dates(): array
    {
        $p = self::get();

        return $p ? date_range($p['start'], $p['end']) : [];
    }

    public static function durationLabel(int $n): string
    {
        return $n.' Day'.($n === 1 ? '' : 's');
    }

    /** "12/12/2026 - 12/15/2026" */
    public static function rangeLabel(string $start, string $end): string
    {
        return date('m/d/Y', strtotime($start)).' - '.date('m/d/Y', strtotime($end));
    }

    /** True when a line's dates fall outside the selected period. */
    public static function outside(array $days): bool
    {
        $p = self::get();
        if (! $p) {
            return false;
        }
        foreach (array_keys($days) as $d) {
            if ($d < $p['start'] || $d > $p['end']) {
                return true;
            }
        }

        return false;
    }
}
