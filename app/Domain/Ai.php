<?php

namespace App\Domain;

use App\Models\Category;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * PartyRent Assist (port of js/core/ai.js).
 *
 * A local, rule-based natural-language layer — NOT a live AI service. It interprets
 * English/Filipino event descriptions into editable filters and builds suggestions
 * ONLY from existing listings, prices and Inventory availability.
 *
 * Guardrails: it may recommend, search, summarize and explain. It never sets prices,
 * accepts or declines requests, reserves stock, verifies payments or cancels anything.
 * To plug in a hosted model, replace interpret()/chat() and keep these guardrails.
 */
class Ai
{
    public const LABEL = 'PartyRent Assist (rule-based assistant · answers from real listings only)';

    public const EVENTS = [
        'Birthday' => ['birthday', 'bday', 'b-day', 'kaarawan', 'birthday party', '1st birthday', 'kiddie party'],
        'Debut' => ['debut', '18th', 'debutante'],
        'Wedding' => ['wedding', 'kasal', 'kasalan', 'reception', 'bride'],
        'Christening' => ['christening', 'binyag', 'baptism', 'binyagan'],
        'Corporate' => ['corporate', 'company', 'office', 'team building', 'seminar', 'conference', 'launch'],
        'Reunion' => ['reunion', 'family gathering', 'salu-salo', 'salo-salo', 'handaan'],
        'Anniversary' => ['anniversary', 'anibersaryo'],
        'Baby Shower' => ['baby shower', 'gender reveal'],
    ];

    public const COLORS = [
        'Pink' => ['pink', 'rosas', 'blush', 'pastel pink'], 'White' => ['white', 'puti'], 'Gold' => ['gold', 'ginto', 'golden', 'champagne'],
        'Blue' => ['blue', 'asul'], 'Red' => ['red', 'pula'], 'Black' => ['black', 'itim'], 'Clear' => ['clear', 'transparent', 'ghost'],
        'Multicolor' => ['colorful', 'makulay', 'rainbow', 'multicolor'], 'Warm White' => ['warm white', 'warm light'],
    ];

    public const THEMES = [
        'Elegant' => ['elegant', 'classy', 'formal', 'sosyal'], 'Pastel' => ['pastel'], 'Princess' => ['princess', 'prinsesa'],
        'Kids' => ['kids', 'kiddie', 'bata', 'children', 'mga bata'], 'Modern' => ['modern', 'minimal'], 'Glam' => ['glam', 'glamorous'],
        'Garden' => ['garden', 'hardin'], 'Carnival' => ['carnival', 'perya'], 'Romantic' => ['romantic'], 'Rustic' => ['rustic'],
    ];

    public const ITEMS = [
        'chairs' => ['chair', 'chairs', 'upuan', 'silya', 'monoblock', 'tiffany', 'chiavari', 'seat'],
        'tables' => ['table', 'tables', 'mesa', 'lamesa'],
        'tents' => ['tent', 'tents', 'canopy', 'tolda', 'trapal', 'shade'],
        'sound' => ['sound', 'speaker', 'speakers', 'mic', 'microphone', 'audio', 'videoke', 'karaoke', 'music', 'tugtog'],
        'lighting' => ['light', 'lights', 'ilaw', 'lighting', 'uplight', 'fairy'],
        'decorations' => ['decoration', 'decorations', 'decor', 'dekorasyon', 'balloon', 'balloons', 'lobo', 'centerpiece', 'centerpieces', 'garland'],
        'backdrops' => ['backdrop', 'arch', 'photo wall', 'stage'],
        'tableware' => ['tablecloth', 'tablecloths', 'table cloth', 'plates', 'utensils', 'pinggan', 'kubyertos', 'glasses', 'cutlery', 'tableware'],
        'catering' => ['chafing', 'catering', 'food warmer', 'popcorn', 'drink station', 'dispenser', 'buffet'],
        'games' => ['games', 'game', 'laro', 'bubble', 'palaro'],
        'furniture' => ['sofa', 'lounge', 'couch'],
        'other' => ['photo booth', 'photobooth', 'props'],
    ];

    private const MONTHS = [['january', 'jan', 'enero'], ['february', 'feb', 'pebrero'], ['march', 'mar', 'marso'], ['april', 'apr', 'abril'], ['may', 'mayo'], ['june', 'jun', 'hunyo'], ['july', 'jul', 'hulyo'], ['august', 'aug', 'agosto'], ['september', 'sep', 'sept', 'setyembre'], ['october', 'oct', 'oktubre'], ['november', 'nov', 'nobyembre'], ['december', 'dec', 'disyembre']];

    private const DEFAULTS = [
        'Birthday' => ['chairs', 'tables', 'tableware', 'decorations', 'backdrops', 'sound'],
        'Debut' => ['chairs', 'tables', 'backdrops', 'lighting', 'decorations', 'sound'],
        'Wedding' => ['chairs', 'tables', 'tableware', 'decorations', 'backdrops', 'lighting', 'sound'],
        'Christening' => ['chairs', 'tables', 'tableware', 'decorations', 'catering'],
        'Corporate' => ['chairs', 'tables', 'sound', 'catering'],
        'Reunion' => ['chairs', 'tables', 'sound', 'catering'],
        'Anniversary' => ['chairs', 'tables', 'decorations', 'lighting', 'sound'],
        'Baby Shower' => ['chairs', 'tables', 'decorations', 'backdrops'],
    ];

    private static function has(string $t, array $words): bool
    {
        foreach ($words as $w) {
            if (preg_match('/(^|[^a-z])'.preg_quote($w, '/').'([^a-z]|$)/i', $t)) {
                return true;
            }
        }

        return false;
    }

    private static function monthIndex(string $word): int
    {
        $word = strtolower($word);
        foreach (self::MONTHS as $i => $names) {
            if (in_array($word, $names, true)) {
                return $i;
            }
        }

        return -1;
    }

    private static function mkDate(int $mi, int $day): ?string
    {
        if ($mi < 0 || $day < 1 || $day > 31) {
            return null;
        }
        $y = now()->year;
        $d = Carbon::create($y, $mi + 1, 1)->addDays($day - 1);
        if ($d->toDateString() < today_iso()) {
            $d = Carbon::create($y + 1, $mi + 1, 1)->addDays($day - 1);
        }

        return $d->toDateString();
    }

    private static function parseDates(string $text): array
    {
        $all = array_merge(...self::MONTHS);
        usort($all, fn ($a, $b) => strlen($b) <=> strlen($a));
        $mw = implode('|', $all);
        if (preg_match('/('.$mw.')\.?\s*(\d{1,2})(?:st|nd|rd|th)?\s*(?:-|–|to|hanggang|until|and|at)\s*(?:('.$mw.')\.?\s*)?(\d{1,2})/i', $text, $m)) {
            $m1 = self::monthIndex($m[1]);
            $m2 = ! empty($m[3]) ? self::monthIndex($m[3]) : $m1;
            $s = self::mkDate($m1, (int) $m[2]);
            $e = self::mkDate($m2, (int) $m[4]);
            if ($s) {
                return ['start' => $s, 'end' => ($e && $e >= $s) ? $e : $s];
            }
        }
        if (preg_match('/('.$mw.')\.?\s*(\d{1,2})/i', $text, $m)) {
            $s = self::mkDate(self::monthIndex($m[1]), (int) $m[2]);
            if ($s) {
                return ['start' => $s, 'end' => $s];
            }
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2})(?:\s*(?:to|-|–)\s*(\d{4}-\d{2}-\d{2}))?/', $text, $m)) {
            return ['start' => $m[1], 'end' => $m[2] ?? $m[1]];
        }
        if (preg_match('/\b(tomorrow|bukas)\b/i', $text)) {
            $s = add_days(today_iso(), 1);

            return ['start' => $s, 'end' => $s];
        }
        if (preg_match('/\b(this weekend|ngayong weekend|sa weekend)\b/i', $text)) {
            $dow = (int) now()->dayOfWeek;
            $add = ((6 - $dow + 7) % 7) ?: 7;
            $s = add_days(today_iso(), $add);

            return ['start' => $s, 'end' => add_days($s, 1)];
        }

        return [];
    }

    /** Natural language → structured filters (every field is editable by the customer). */
    public static function interpret(string $text): array
    {
        $t = ' '.mb_strtolower($text).' ';
        $out = ['raw' => $text, 'eventType' => null, 'guests' => null, 'locationType' => null, 'colors' => [], 'themes' => [], 'items' => [], 'start' => null, 'end' => null, 'city' => null, 'budget' => null, 'delivery' => null,
            'language' => preg_match('/\b(ng|para|sa|mga|kailangan|gusto|namin|ako|bisita|upuan|mesa)\b/', $t) ? 'Filipino' : 'English'];
        foreach (self::EVENTS as $ev => $words) {
            if (self::has($t, $words)) {
                $out['eventType'] = $ev;
                break;
            }
        }
        if (preg_match('/(\d{1,4})\s*(?:\+\s*)?(guests?|pax|people|persons?|bisita|katao|tao|visitors|attendees|heads)/i', $t, $g)
            || preg_match('/(?:for|para sa)\s+(\d{2,4})\b(?!\s*(?:am|pm|days|araw))/i', $t, $g)) {
            $out['guests'] = (int) $g[1];
        }
        if (self::has($t, ['outdoor', 'outdoors', 'garden', 'labas', 'open air', 'backyard', 'beach', 'poolside', 'bakuran', 'kalsada', 'street'])) {
            $out['locationType'] = 'Outdoor';
        } elseif (self::has($t, ['indoor', 'indoors', 'loob', 'hall', 'function room', 'clubhouse', 'ballroom'])) {
            $out['locationType'] = 'Indoor';
        }
        foreach (self::COLORS as $c => $words) {
            if (self::has($t, $words)) {
                $out['colors'][] = $c;
            }
        }
        if (in_array('Warm White', $out['colors'], true) && ! preg_match('/\bwhite\b(?!\s*light)/', str_replace('warm white', '', $t))) {
            $out['colors'] = array_values(array_filter($out['colors'], fn ($c) => $c !== 'White'));
        }
        foreach (self::THEMES as $th => $words) {
            if (self::has($t, $words)) {
                $out['themes'][] = $th;
            }
        }
        foreach (self::ITEMS as $cat => $words) {
            if (self::has($t, $words)) {
                $out['items'][] = $cat;
            }
        }
        $d = self::parseDates($t);
        if (! empty($d['start'])) {
            $out['start'] = $d['start'];
            $out['end'] = $d['end'];
        }
        if ($c = Locations::guessCityInText($t)) { // any city/municipality in the locations dataset
            $out['city'] = $c['name'];
        }
        if (preg_match('/(?:₱|php|p)\s?([\d,]+(?:\.\d+)?)\s*(k)?|([\d,]+)\s*(k)?\s*(?:pesos|piso|budget)|budget\s*(?:of|ay|na)?\s*₱?\s*([\d,]+)\s*(k)?/iu', $t, $bm)) {
            $num = (float) str_replace(',', '', $bm[1] ?: ($bm[3] ?? '') ?: ($bm[5] ?? ''));
            $k = ($bm[2] ?? '') ?: ($bm[4] ?? '') ?: ($bm[6] ?? '');
            if ($num > 0) {
                $out['budget'] = $k ? $num * 1000 : $num;
            }
        }
        if (self::has($t, ['deliver', 'delivery', 'ihatid', 'hatid'])) {
            $out['delivery'] = true;
        }
        if (self::has($t, ['pickup', 'pick up', 'pick-up', 'kukunin'])) {
            $out['delivery'] = false;
        }

        return $out;
    }

    /* ---------- listings helpers (existing data only) ---------- */

    private static ?Collection $live = null;

    public static function liveProducts(): Collection
    {
        if (self::$live === null) {
            self::$live = Product::live()->with('supplier')->get();
            inv()->primeProducts(self::$live);
        }

        return self::$live;
    }

    private static function datesOf(array $f): array
    {
        return ! empty($f['start']) ? date_range($f['start'], $f['end'] ?: $f['start']) : [];
    }

    private static function score(Product $p, array $f): float
    {
        $s = (float) $p->rating;
        foreach ($f['colors'] ?? [] as $c) {
            $s += in_array($c, $p->colors ?? [], true) ? 3 : 0;
        }
        foreach ($f['themes'] ?? [] as $c) {
            $s += in_array($c, $p->themes ?? [], true) ? 2 : 0;
        }
        if (! empty($f['eventType']) && in_array($f['eventType'], $p->events ?? [], true)) {
            $s += 2;
        }
        $kidsItem = preg_match('/kid|kiddie/i', $p->name) || (in_array('Kids', $p->themes ?? [], true) && count($p->themes ?? []) === 1);
        if ($kidsItem && ! in_array('Kids', $f['themes'] ?? [], true)) {
            $s -= 5;
        }
        $sup = $p->supplier;
        if (! empty($f['city'])) {
            $cityCode = Locations::findCityByName($f['city'])['code'] ?? null;
            if ($sup->city === $f['city']) {
                $s += 1.5;
            } elseif ($cityCode && $sup->deliversTo($cityCode)) {
                $s += 1;
            } elseif ($cityCode && ($f['delivery'] ?? null) !== false) {
                $s -= 3; // can't deliver to the event city
            }
        }
        if (($f['delivery'] ?? null) === true && $sup->fulfillment === 'pickup') {
            $s -= 4;
        }
        if (($f['delivery'] ?? null) === false && $sup->fulfillment === 'delivery') {
            $s -= 4;
        }

        return $s;
    }

    private static function estCost(Product $p, int $qty, array $dates): float
    {
        return r2(Pricing::unitPrice($p, $qty) * $qty * max(1, count($dates)));
    }

    /** Suggested quantity for a category, given guest count. @return array{0:int,1:string} */
    private static function qtyFor(string $cat, Product $p, array $f): array
    {
        $G = $f['guests'] ?: 50;
        $tables = (int) ceil($G / 8);

        return match ($cat) {
            'chairs' => [$G, "One seat per guest ($G guests)."],
            'tables' => preg_match('/kiddie/i', $p->name) ? [(int) ceil($G / 6), '6 kids per kiddie table.'] : (preg_match('/banquet|rectangular/i', $p->name) ? [(int) ceil($G / 9), 'About 8–10 guests per long table.'] : [$tables, '8 guests per round table.']),
            'tableware' => preg_match('/tablecloth|cloth/i', $p->name) ? [$tables, "One cloth for each of the $tables tables."] : [(int) ceil($G / 10), 'One set serves 10 guests.'],
            'decorations' => preg_match('/centerpiece/i', $p->name) ? [$tables, 'One centerpiece per guest table.'] : [1, 'A focal décor piece for the venue.'],
            'tents' => (function () use ($p, $G, $f) {
                $cap = str_contains($p->name, '20×20') ? 60 : 25;

                return [(int) ceil($G / $cap), "Each tent shelters about $cap seated guests".(($f['locationType'] ?? '') === 'Outdoor' ? ' (outdoor event).' : '.')];
            })(),
            'sound' => [1, $G > 150 ? 'A large crowd needs a pro system.' : 'For music, program and speeches.'],
            'lighting' => [preg_match('/curtain/i', $p->name) ? 2 : 1, 'Sets the mood for evening programs.'],
            'backdrops' => [1, 'Stage and photo area backdrop.'],
            'catering' => preg_match('/chafing/i', $p->name) ? [max(2, (int) ceil($G / 25)), 'About one chafing dish per 25 guests.'] : [1, 'Keeps food and drinks flowing.'],
            'games' => [1, 'Entertainment for kids.'],
            'furniture' => [1, 'A lounge area for VIPs or elders.'],
            default => [1, 'Popular add-on for this event.'],
        };
    }

    /** Ranked candidates for one category, with real date-by-date availability. */
    public static function candidates(string $cat, array $f): array
    {
        $dates = self::datesOf($f);
        $out = [];
        foreach (self::liveProducts()->where('category_id', $cat) as $p) {
            [$qty, $reason] = self::qtyFor($cat, $p, $f);
            $avail = inv()->minAvailable($p->id, $dates);
            $out[] = ['product' => $p, 'supplier' => $p->supplier, 'qty' => $qty, 'reason' => $reason, 'available' => $avail, 'enough' => $avail >= $qty, 'cost' => self::estCost($p, $qty, $dates), 'score' => self::score($p, $f) + ($avail >= $qty ? 5 : 0)];
        }
        usort($out, fn ($a, $b) => [$b['score'], $a['cost']] <=> [$a['score'], $b['cost']]);

        return $out;
    }

    /** Event checklist with suggested listings. Nothing is added to the cart automatically. */
    public static function plan(array $f): array
    {
        $cats = $f['items'] ?: (self::DEFAULTS[$f['eventType'] ?? ''] ?? self::DEFAULTS['Birthday']);
        if (($f['locationType'] ?? '') === 'Outdoor' && ! in_array('tents', $cats, true)) {
            $cats[] = 'tents';
        }
        if (($f['eventType'] ?? '') === 'Birthday' && in_array('Kids', $f['themes'] ?? [], true) && ! in_array('games', $cats, true)) {
            $cats[] = 'games';
        }
        $rows = [];
        foreach ($cats as $cat) {
            $list = self::candidates($cat, $f);
            $best = collect($list)->first(fn ($c) => $c['enough']) ?? ($list[0] ?? null);
            $rows[] = ['category' => $cat, 'best' => $best, 'alternatives' => array_slice(array_values(array_filter($list, fn ($c) => $c !== $best)), 0, 3), 'none' => ! $list];
        }
        $dates = self::datesOf($f);
        $pkgs = [];
        foreach (Package::live()->with('supplier')->get() as $k) {
            if (! empty($f['eventType']) && ! in_array($f['eventType'], $k->events ?? [], true)) {
                continue;
            }
            $avail = inv()->packageMinAvailable($k->id, $dates);
            if ($avail > 0) {
                $pkgs[] = ['pkg' => $k, 'available' => $avail, 'cost' => $k->price * max(1, count($dates))];
            }
        }
        $total = array_sum(array_map(fn ($r) => $r['best'] && $r['best']['enough'] ? $r['best']['cost'] : 0, $rows));

        return ['filters' => $f, 'dates' => $dates, 'rows' => $rows, 'packages' => $pkgs, 'total' => $total, 'overBudget' => ! empty($f['budget']) && $total > $f['budget']];
    }

    /** Similar AVAILABLE items when the chosen one cannot cover the requested quantity. */
    public static function alternatives(Product $p, array $days): array
    {
        $dates = array_keys($days);
        $out = [];
        foreach (self::liveProducts()->where('category_id', $p->category_id)->where('id', '!=', $p->id) as $x) {
            foreach ($dates as $d) {
                if (inv()->availableOn($x->id, $d) < (int) $days[$d]) {
                    continue 2;
                }
            }
            $sameColor = (bool) array_intersect($x->colors ?? [], $p->colors ?? []);
            $sameTheme = (bool) array_intersect($x->themes ?? [], $p->themes ?? []);
            $reason = $x->supplier_id !== $p->supplier_id
                ? ($sameColor ? 'Same color from another supplier' : 'Different style from another supplier')
                : ($sameColor ? 'Similar item from the same shop' : 'Alternative design from the same shop');
            $out[] = ['product' => $x, 'supplier' => $x->supplier, 'reason' => $reason.($sameTheme ? ', matches your theme' : ''), 'score' => ($sameColor ? 3 : 0) + ($sameTheme ? 2 : 0) + $x->rating, 'unit' => Pricing::unitPrice($x, max(array_map('intval', $days) ?: [1]))];
        }
        $catName = mb_strtolower(Category::find($p->category_id)?->name ?? 'items');
        foreach (Package::live()->with('supplier')->get() as $k) {
            $hasCat = false;
            foreach ($k->items as $it) {
                if (inv()->product($it['product_id'])?->category_id === $p->category_id) {
                    $hasCat = true;
                }
            }
            if (! $hasCat) {
                continue;
            }
            foreach ($dates as $d) {
                if (inv()->packageAvailableOn($k->id, $d) < 1) {
                    continue 2;
                }
            }
            $out[] = ['pkg' => $k, 'supplier' => $k->supplier, 'reason' => 'Package that includes '.$catName, 'score' => 2];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, 5);
    }

    /* ---------- assistant chatbot ---------- */

    private const FORBIDDEN = '/(accept|approve|decline|cancel|reserve|book it|verify|discount|lower (the )?price|bawasan|tawad|change (the )?price|mark (as )?paid|refund me|i-accept|i-cancel)/i';

    private static function a(string $url, string $text): string
    {
        return '<a href="'.e($url).'">'.e($text).'</a>';
    }

    /** @return array{html:string, chips:array} */
    public static function chat(string $message, ?User $user): array
    {
        $t = ' '.mb_strtolower(trim($message)).' ';
        $reply = fn (string $html, array $chips = []) => ['html' => $html, 'chips' => $chips];
        if (trim($t) === '') {
            return $reply('Please type a question. 😊');
        }
        if (preg_match(self::FORBIDDEN, $t) && ! preg_match('/how|paano|policy|can i|pwede/', $t)) {
            return $reply("I can't do that — I'm an assistant only. Accepting, cancelling, reserving, changing prices and verifying payments are always done by you, the supplier or our cashier. I can explain how to do it or show you where: ".self::a(route('rentals.index'), 'My Rentals').'.');
        }
        if (preg_match('/^\s*(hi|hello|hey|kumusta|kamusta|good (morning|afternoon|evening))\b/', $t)) {
            return $reply('Hi'.($user ? ' '.e($user->firstName()) : '').'! 🎉 I can find party supplies, check availability, explain payments, or track your requests. What are you planning?', ['Plan a party for 50 guests', 'Monoblock chair prices', 'My request status']);
        }

        // Request status — only the signed-in customer's own records.
        $hasCode = preg_match('/pr-\d{3,5}/i', $t, $prm);
        if ($hasCode || preg_match('/(status|my request|my rental|request ko|order ko|track)/', $t)) {
            if (! $user) {
                return $reply('Please '.self::a(route('login'), 'log in').' so I can look up your own rental requests.');
            }
            if ($hasCode) {
                $code = strtoupper($prm[0]);
                $r = RentalRequest::where('code', $code)->where('customer_id', $user->id)->with('payments')->first();
                if (! $r) {
                    return $reply("I can't find ".e($code).' in your account. For privacy I can only show your own requests.');
                }
                $f = $r->fin();

                return $reply('<b>'.e($r->code).'</b> — '.e($r->event_name).'<br>Status: <b>'.e(RentalRequest::statusLabel($r->status)).'</b><br>Payment: <b>'.e($f['status']).'</b> · Balance '.money($f['balance']).($f['next'] ? '<br>Next: '.e($f['next']['label']).' due '.fmt_date($f['next']['due']) : '').'<br>'.self::a(route('rentals.show', $r), 'Open details →'));
            }
            $mine = RentalRequest::where('customer_id', $user->id)->whereNotIn('status', ['completed', 'declined', 'cancelled'])->with('payments')->orderBy('start_date')->get();
            if ($mine->isEmpty()) {
                return $reply('You have no active rental requests right now. '.self::a(route('home'), 'Browse supplies →'));
            }

            return $reply('Your active requests:<br>'.$mine->map(fn ($r) => '• '.self::a(route('rentals.show', $r), $r->code).' '.e($r->event_name).' — <b>'.e(RentalRequest::statusLabel($r->status)).'</b>, '.e($r->fin()['status']))->implode('<br>'));
        }
        if (preg_match('/(become a supplier|start selling|magbenta|sell my|mag-supplier|rent out my|open a shop)/', $t)) {
            return $reply('You can become a supplier with the same account: open your profile menu → <b>Start Selling</b>, complete the 6-step form, and once approved you can switch between Customer and Supplier Mode. '.self::a(route('start-selling'), 'Start Selling →'));
        }
        if (preg_match('/(how (do|to) (i )?rent|paano (mag)?renta|paano umupa|how does it work|steps)/', $t)) {
            return $reply("Renting on PartyRent:<br>1. Find items or a package and pick dates & quantities (you can set a different quantity per day).<br>2. Add to your Rental List and submit a request.<br>3. The supplier reviews and accepts (it stays <b>Pending</b> until then).<br>4. Pay according to the supplier's payment policy — payments count once verified by our cashier.<br>5. Receive the items, enjoy, then return them for inspection.");
        }
        if (preg_match('/(plan|planning|prepare|help me|tulungan|mag-?organize).*(party|event|wedding|debut|birthday|kasal|binyag|reunion)|\d+\s*(guests|bisita|pax)/', $t)) {
            return $reply('Let me build a checklist from real listings. '.self::a(route('ai', ['q' => $message]), 'Open the AI Event Planner with your description →'));
        }

        // product / supplier lookup
        $cats = array_keys(array_filter(self::ITEMS, fn ($w) => self::has($t, $w)));
        $sup = Supplier::approved()->get()->first(function ($s) use ($t) {
            $first = explode(' ', $s->name)[0];

            return mb_strlen($first) > 3 && str_contains($t, mb_strtolower($first));
        });
        $list = self::liveProducts();
        if ($sup) {
            $list = $list->where('supplier_id', $sup->id);
        }
        if ($cats) {
            $list = $list->whereIn('category_id', $cats);
        }
        $f = self::interpret($t);
        if ($f['colors']) {
            $c = $list->filter(fn ($p) => array_intersect($f['colors'], $p->colors ?? []));
            if ($c->isNotEmpty()) {
                $list = $c;
            }
        }
        $list = ($cats || $sup) ? $list->values() : collect();

        if (preg_match('/(deposit|security)/', $t)) {
            return $reply('A <b>security deposit</b> is refundable and kept separate from your rental charges. After the items are returned and inspected, the supplier refunds it in full or deducts repair/replacement fees for damaged or missing items — you will see the damage report first.'.($sup ? '<br>'.e($sup->name).': '.e($sup->depositLabel()).'.' : ''));
        }
        if (preg_match('/(pay|payment|bayad|down ?payment|dp|reservation fee|installment|hulugan|gcash|bank|cod|cash)/', $t)) {
            if ($sup) {
                $plans = implode('<br>', array_map(fn ($k) => '<b>'.e(\App\Domain\Finance::PLAN_LABEL[$k]).'</b>: '.e(\App\Domain\Finance::planSummary($sup, $k)), \App\Domain\Finance::plansOf($sup)));

                return $reply('<b>'.e($sup->name).'</b> accepts '.e(implode(', ', array_map(fn ($m) => Payment::METHOD_LABEL[$m] ?? $m, $sup->acceptedMethods()))).'.<br>'.$plans);
            }

            return $reply('Each supplier sets its own payment plans: <b>Full Payment</b>, <b>Down Payment</b> + final balance (e.g. 30% down), or <b>Installments</b>. There is no separate reservation fee — once your down payment (or full payment) is verified, your booking is reserved and marked Partially Paid. The down payment is credited to your total, never charged twice. Ask me about a specific shop, e.g. "Happy Moments payment policy".', ['Happy Moments payment policy', 'Elegant Occasion payment policy']);
        }
        if (preg_match('/(deliver|delivery|pickup|pick up|hatid|kunin)/', $t)) {
            $show = $sup ? collect([$sup]) : Supplier::approved()->get();

            return $reply($show->map(fn ($s) => '• <b>'.e($s->name).'</b> ('.e($s->city).'): '.e($s->fulfillmentLabel()).($s->offersDelivery() ? ' — '.e($s->deliverySummary()) : ''))->implode('<br>'));
        }
        if (preg_match('/(cancel|cancellation|ikansela)/', $t)) {
            return $reply('You can cancel a <b>Pending</b> request anytime. For accepted requests, each supplier has a free-cancellation window (for example, 3 days before the rental). Open the request in '.self::a(route('rentals.index'), 'My Rentals').' and use <b>Cancel request</b> to see the exact policy before confirming.');
        }
        if ($list->isNotEmpty() && preg_match('/(available|availability|may available|meron|libre|free on|in stock|stock)/', $t)) {
            $dates = $f['start'] ? date_range($f['start'], $f['end']) : [today_iso()];
            $q = $f['guests'] ?: (preg_match('/(\d+)\s*(pcs|pieces|piraso|units|chairs|tables|upuan|mesa)/', $t, $qm) ? (int) $qm[1] : null);

            return $reply('Availability for '.fmt_range($dates[0], end($dates)).($q ? " (need $q)" : '').':<br>'.$list->take(5)->map(function ($p) use ($dates, $q) {
                $a = inv()->minAvailable($p->id, $dates);

                return '• '.self::a(route('products.show', $p), $p->name).' — '.e($p->supplier->name).': <b>'.$a.'</b> available'.($q ? ($a >= $q ? ' ✅' : ' ❌') : '');
            })->implode('<br>').($f['start'] ? '' : '<br><span class="muted">Tip: add a date, e.g. "chairs available on Nov 20".</span>'));
        }
        if ($list->isNotEmpty()) {
            return $reply('Here is what I found on PartyRent:<br>'.$list->take(5)->map(fn ($p) => '• '.self::a(route('products.show', $p), $p->name).' — '.e($p->supplier->name).': <b>'.money($p->basePrice()).'</b>/'.e($p->unitWord()).(count($p->tiers) > 1 ? ' (as low as '.money($p->lowestPrice()).' in bulk)' : ''))->implode('<br>').($list->count() > 5 ? '<br>'.self::a(route('home', ['category' => $list->first()->category_id]), 'See all '.$list->count().' →') : ''));
        }
        if ($sup) {
            return $reply('<b>'.e($sup->name).'</b> — '.e($sup->city).', rated '.$sup->rating.'★. '.e($sup->description).'<br>'.self::a(route('suppliers.show', $sup), 'Visit shop →'));
        }

        return $reply("I'm not sure about that, and I don't want to guess. I can only answer using PartyRent's listings, supplier policies and your own requests. Try asking about prices, availability, payments, delivery or your request status — or contact support at ".e(Settings::get('supportEmail')).'.', ['How do I rent?', 'Tiffany chair prices', 'Delivery options']);
    }
}
