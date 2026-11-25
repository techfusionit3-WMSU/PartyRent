<?php

namespace App\Support;

use App\Domain\Slots;
use Illuminate\Validation\ValidationException;

/**
 * Operating hours per day of the week: ['mon' => ['closed' => false, 'open' => '09:00', 'close' => '18:00'], …].
 * Saved in suppliers.weekly_hours; suppliers.hours keeps a one-line summary ("Mon–Sat 9:00 AM – 6:00 PM · Sun closed").
 */
class WeeklyHours
{
    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    public const SHORT = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    public static function defaults(): array
    {
        $w = [];
        foreach (array_keys(self::DAYS) as $d) {
            $w[$d] = ['closed' => $d === 'sun', 'open' => '09:00', 'close' => '18:00'];
        }

        return $w;
    }

    private static function hhmm($v): ?string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
    }

    /** Clean and check the form input; $field is the input name for error messages. */
    public static function fromInput(array $in, string $field = 'weekly_hours'): array
    {
        $w = [];
        $errors = [];
        foreach (self::DAYS as $d => $name) {
            $row = (array) ($in[$d] ?? []);
            $closed = filter_var($row['closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $open = self::hhmm($row['open'] ?? null);
            $close = self::hhmm($row['close'] ?? null);
            if (! $closed) {
                if (! $open || ! $close) {
                    $errors[$field.'.'.$d] = $name.': enter the opening and closing time, or mark it closed.';
                } elseif ($close <= $open) {
                    $errors[$field.'.'.$d] = $name.': the closing time must be after the opening time.';
                }
            }
            $w[$d] = ['closed' => $closed, 'open' => $open ?? '09:00', 'close' => $close ?? '18:00'];
        }
        if (! $errors && collect($w)->every(fn ($r) => $r['closed'])) {
            $errors[$field] = 'Open on at least one day of the week.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $w;
    }

    /** "Mon–Sat 9:00 AM – 6:00 PM · Sun closed" (days with the same hours grouped). */
    public static function summary(?array $w): string
    {
        if (! $w) {
            return '';
        }
        $keys = array_keys(self::DAYS);
        $groups = [];
        foreach ($keys as $d) {
            $r = $w[$d] ?? ['closed' => true];
            $label = ! empty($r['closed']) ? 'closed' : Slots::fmt12($r['open']).' – '.Slots::fmt12($r['close']);
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['label'] === $label) {
                $groups[$last]['to'] = $d;
            } else {
                $groups[] = ['from' => $d, 'to' => $d, 'label' => $label];
            }
        }

        return mb_substr(implode(' · ', array_map(fn ($g) => self::SHORT[$g['from']].($g['to'] !== $g['from'] ? '–'.self::SHORT[$g['to']] : '').' '.$g['label'], $groups)), 0, 120);
    }

    /** Rows for display: [[day name, "9:00 AM – 6:00 PM" | "Closed"], …]. */
    public static function rows(?array $w): array
    {
        return array_map(fn ($d, $name) => [$name, ! empty($w[$d]['closed']) ? 'Closed' : Slots::fmt12($w[$d]['open'] ?? '09:00').' – '.Slots::fmt12($w[$d]['close'] ?? '18:00')], array_keys(self::DAYS), self::DAYS);
    }
}
