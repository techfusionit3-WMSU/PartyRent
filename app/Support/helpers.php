<?php

use App\Domain\Inventory;
use App\Domain\Settings;
use Carbon\Carbon;
use Illuminate\Support\HtmlString;

/* Formatting and view helpers shared by every page (ported from js/core/utils.js). */

if (! function_exists('r2')) {
    function r2(float|int|string|null $n): float
    {
        return round((float) $n, 2);
    }
}

if (! function_exists('currency_symbol')) {
    /** The one place the currency comes from (config/partyrent.php). */
    function currency_symbol(): string
    {
        return (string) config('partyrent.currency.symbol', '₱');
    }
}

if (! function_exists('money')) {
    function money(float|int|string|null $n): string
    {
        return currency_symbol().number_format(r2($n), 2);
    }
}

if (! function_exists('money_short')) {
    function money_short(float|int|string|null $n): string
    {
        return currency_symbol().number_format(round((float) $n));
    }
}

if (! function_exists('customer_city')) {
    /** City the shopper wants things delivered to: the "deliver to" pick for this session, else the saved address. */
    function customer_city(): ?string
    {
        $c = session('deliver_to') ?: auth()->user()?->city_code;

        return $c && \App\Domain\Locations::isCity($c) ? $c : null;
    }
}

if (! function_exists('format_address')) {
    /** Display string for a structured address (see App\Domain\Locations). */
    function format_address(?array $parts, bool $withLandmark = true): string
    {
        return \App\Domain\Locations::formatAddress($parts, $withLandmark);
    }
}

if (! function_exists('today_iso')) {
    function today_iso(): string
    {
        return now()->toDateString();
    }
}

if (! function_exists('add_days')) {
    function add_days(string $date, int $n): string
    {
        return Carbon::parse($date)->addDays($n)->toDateString();
    }
}

if (! function_exists('add_months')) {
    /** Same day n months later, clamped to the month's last day (U.addMonths). */
    function add_months(string $date, int $n): string
    {
        return Carbon::parse($date)->addMonthsNoOverflow($n)->toDateString();
    }
}

if (! function_exists('diff_days')) {
    function diff_days(string $a, string $b): int
    {
        return (int) Carbon::parse($a)->diffInDays(Carbon::parse($b), false);
    }
}

if (! function_exists('date_range')) {
    /** Inclusive list of ISO dates (capped at 400 days). */
    function date_range(?string $start, ?string $end): array
    {
        if (! $start || ! $end) {
            return [];
        }
        $out = [];
        $d = $start;
        $guard = 0;
        while ($d <= $end && $guard < 400) {
            $out[] = $d;
            $d = add_days($d, 1);
            $guard++;
        }

        return $out;
    }
}

if (! function_exists('fmt_date')) {
    function fmt_date(mixed $d, bool $withYear = true): string
    {
        if (! $d) {
            return '—';
        }

        return Carbon::parse($d)->format($withYear ? 'M j, Y' : 'M j');
    }
}

if (! function_exists('fmt_day')) {
    function fmt_day(string $d): string
    {
        return Carbon::parse($d)->format('D, M j');
    }
}

if (! function_exists('fmt_datetime')) {
    function fmt_datetime(mixed $ts): string
    {
        if (! $ts) {
            return '—';
        }

        return Carbon::parse($ts)->timezone(config('app.timezone'))->format('M j, Y · g:i A');
    }
}

if (! function_exists('fmt_range')) {
    function fmt_range(?string $a, ?string $b): string
    {
        if (! $a) {
            return '—';
        }
        if (! $b || $a === $b) {
            return fmt_date($a);
        }
        $da = Carbon::parse($a);
        $db = Carbon::parse($b);
        if ($da->year === $db->year) {
            if ($da->month === $db->month) {
                return $da->format('M j').'–'.$db->format('j, Y');
            }

            return $da->format('M j').' – '.$db->format('M j, Y');
        }

        return fmt_date($a).' – '.fmt_date($b);
    }
}

if (! function_exists('time_ago')) {
    function time_ago(mixed $ts): string
    {
        $s = now()->getTimestamp() - Carbon::parse($ts)->getTimestamp();
        if ($s < 60) {
            return 'just now';
        }
        $m = intdiv($s, 60);
        if ($m < 60) {
            return $m.'m ago';
        }
        $h = intdiv($m, 60);
        if ($h < 24) {
            return $h.'h ago';
        }
        $d = intdiv($h, 24);

        return $d < 30 ? $d.'d ago' : fmt_date($ts);
    }
}

if (! function_exists('rating_text')) {
    /** "4.8 (12)" from real reviews, or "No reviews yet". Accepts a Product or Supplier. */
    function rating_text(object $m): string
    {
        $n = (int) ($m->review_count ?? 0);

        if ($n) {
            return number_format((float) $m->rating, 1).' ('.$n.')';
        }

        // seeded ratings (reference seed) have no review rows behind them yet
        return (float) ($m->rating ?? 0) > 0 ? number_format((float) $m->rating, 1) : 'No reviews yet';
    }
}

if (! function_exists('stars')) {
    function stars(float|int|null $r): string
    {
        $r = round(((float) $r) * 2) / 2;
        $out = '';
        for ($i = 1; $i <= 5; $i++) {
            $out .= $r >= $i - 0.5 ? '★' : '☆';
        }

        return $out;
    }
}

if (! function_exists('icon')) {
    /** Inline SVG icon from the prototype's icon set. */
    function icon(string $name, string $style = ''): HtmlString
    {
        static $set = null;
        $set ??= require app_path('Support/icons.php');
        $paths = $set['icons'][$name] ?? $set['icons']['info'];

        return new HtmlString('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'.($style ? ' style="'.e($style).'"' : '').'>'.$paths.'</svg>');
    }
}

if (! function_exists('art')) {
    /** White line illustration drawn on a category gradient. */
    function art(string $name, string $size = '48%'): HtmlString
    {
        static $set = null;
        $set ??= require app_path('Support/icons.php');
        $paths = $set['art'][$name] ?? $set['art']['gift'];

        return new HtmlString('<svg viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" style="width:'.$size.';height:'.$size.';filter:drop-shadow(0 4px 8px rgba(0,0,0,.15))" aria-hidden="true">'.$paths.'</svg>');
    }
}

if (! function_exists('confetti_svg')) {
    function confetti_svg(): HtmlString
    {
        $c = ['#FDE68A', '#fff', '#99F6E4', '#FBCFE8', '#C4B5FD'];
        $s = '';
        for ($i = 0; $i < 40; $i++) {
            $x = ($i * 97) % 400;
            $y = ($i * 53) % 400;
            $r = ($i * 37) % 180;
            $s .= $i % 3
                ? '<rect x="'.$x.'" y="'.$y.'" width="10" height="4" rx="2" fill="'.$c[$i % 5].'" transform="rotate('.$r.' '.$x.' '.$y.')"/>'
                : '<circle cx="'.$x.'" cy="'.$y.'" r="4" fill="'.$c[$i % 5].'"/>';
        }

        return new HtmlString($s);
    }
}

if (! function_exists('color_hex')) {
    function color_hex(string $c): string
    {
        return [
            'White' => '#FFFFFF', 'Pink' => '#F9A8D4', 'Gold' => '#D4A017', 'Champagne' => '#E8D3A8', 'Blue' => '#60A5FA',
            'Red' => '#EF4444', 'Black' => '#1F2937', 'Clear' => '#E0F2FE', 'Warm White' => '#FFF4D6', 'Silver' => '#CBD5E1',
            'Multicolor' => 'conic-gradient(#f43f5e,#f59e0b,#10b981,#3b82f6,#a855f7,#f43f5e)',
        ][$c] ?? '#ddd';
    }
}

if (! function_exists('inv')) {
    function inv(): Inventory
    {
        return app(Inventory::class);
    }
}

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Settings::get($key, $default);
    }
}

if (! function_exists('active_role')) {
    /** Role the current session is acting as (customer | supplier | cashier | admin | superadmin), or null for guests. */
    function active_role(): ?string
    {
        $u = auth()->user();

        return $u ? $u->activeRole(session('mode')) : null;
    }
}

if (! function_exists('rental_period')) {
    /** The customer's selected rental period ['start', 'end'] or null (App\Support\RentalPeriod). */
    function rental_period(): ?array
    {
        return \App\Support\RentalPeriod::get();
    }
}

if (! function_exists('rental_period_outside')) {
    function rental_period_outside(array $days): bool
    {
        return \App\Support\RentalPeriod::outside($days);
    }
}
