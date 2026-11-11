<?php

namespace App\Http\Controllers;

use App\Domain\Ai;
use App\Domain\Locations;
use App\Domain\Rentals;
use App\Models\Category;
use App\Models\Supplier;
use Illuminate\Http\Request;

class AiController extends Controller
{
    /**
     * AI Event Planner. Step 1: interpret the description (?q=). Step 2: the customer edits the
     * interpreted filters and asks for suggestions (?plan=1 + filter fields).
     */
    public function planner(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $filters = null;
        $plan = null;
        if ($request->query('search')) {
            $f = $this->filtersFrom($request);

            return redirect()->route('browse', array_filter([
                'category' => count($f['items']) === 1 ? $f['items'][0] : null, 'event' => $f['eventType'], 'color' => $f['colors'][0] ?? null,
                // The event city becomes the "deliver to" city, so results show who can deliver there.
                'theme' => $f['themes'][0] ?? null, 'start' => $f['start'], 'end' => $f['end'], 'to' => Locations::findCityByName($f['city'])['code'] ?? null,
                'delivery' => $f['delivery'] ? '1' : null, 'avail' => $f['start'] ? '1' : null,
            ]));
        }
        if ($request->query('plan')) {
            $filters = $this->filtersFrom($request);
            if ($filters['start'] && $filters['end'] && $filters['end'] < $filters['start']) {
                return back()->with('error', 'End date must be after the start date.');
            }
            $plan = Ai::plan($filters);
        } elseif ($q !== '') {
            $filters = Ai::interpret(mb_substr($q, 0, 1000));
            if ($filters['eventType'] || $filters['items'] || $filters['guests']) {
                $plan = Ai::plan($filters);
            }
        }
        $today = today_iso();
        $examples = [
            'Outdoor birthday' => 'I am planning an outdoor birthday party for 50 guests. I need chairs, tables, a tent, pink decorations, and a sound system for '.fmt_date(add_days($today, 13), false).' to '.fmt_date(add_days($today, 15), false).'.',
            'White & gold wedding' => 'Help me prepare for a wedding reception for 100 guests with an elegant white and gold theme on '.fmt_date(add_days($today, 30), false).'.',
            'Binyag (Filipino)' => 'Kailangan namin ng upuan at mesa para sa 80 bisita, binyag sa '.date('F j', strtotime(add_days($today, 20))).', may delivery sa Quezon City.',
        ];

        return view('market.ai', [
            'q' => $q, 'filters' => $filters, 'plan' => $plan, 'examples' => $examples,
            'cats' => Category::pluck('name', 'id'),
            // Suggest only cities where an approved supplier is based, busiest first.
            'cities' => Supplier::approved()->whereNotNull('city')->where('city', '!=', '')->pluck('city')
                ->countBy()->sortDesc(),
        ]);
    }

    private function filtersFrom(Request $r): array
    {
        $arr = fn ($k, $allowed) => array_values(array_intersect((array) $r->query($k, []), $allowed));
        $start = $r->query('start') ?: null;

        return [
            'raw' => (string) $r->query('q', ''), 'language' => (string) $r->query('language', 'English'),
            'eventType' => in_array($r->query('eventType'), array_keys(Ai::EVENTS), true) ? $r->query('eventType') : null,
            'guests' => ($g = (int) $r->query('guests')) > 0 ? min($g, 2000) : null,
            'start' => $start && $start >= today_iso() ? $start : null,
            'end' => $start ? ($r->query('end') ?: $start) : null,
            'locationType' => in_array($r->query('locationType'), ['Indoor', 'Outdoor'], true) ? $r->query('locationType') : null,
            'city' => $r->query('city') ?: null,
            'budget' => ($b = (float) $r->query('budget')) > 0 ? $b : null,
            'delivery' => $r->query('delivery') === '1' ? true : ($r->query('delivery') === '0' ? false : null),
            'colors' => $arr('colors', array_keys(Ai::COLORS)),
            'themes' => $arr('themes', array_keys(Ai::THEMES)),
            'items' => $arr('items', array_keys(Ai::ITEMS)),
        ];
    }

    /** Add the ticked checklist rows to the Rental List (each is re-checked against live stock). */
    public function plan(Request $request)
    {
        $u = $request->user();
        if (! $u) {
            return redirect()->guest(route('login'))->with('warn', 'Log in to save items to your Rental List.');
        }
        if ($u->activeRole(session('mode')) !== 'customer') {
            return back()->with('warn', 'Switch to Customer Mode to rent items.');
        }
        $start = $request->input('start');
        $end = $request->input('end') ?: $start;
        if (! $start) {
            return back()->with('warn', 'Please set your event dates first so availability can be checked.');
        }
        $dates = date_range($start, $end);
        $added = 0;
        $skipped = [];
        foreach ((array) $request->input('pick', []) as $pid => $on) {
            $qty = max(1, (int) $request->input("qty.$pid", 1));
            $res = Rentals::addToCart($u, 'item', (int) $pid, array_fill_keys($dates, $qty));
            if ($res['ok']) {
                $added++;
            } else {
                $skipped[] = (inv()->product($pid)?->name ?? 'item').': '.$res['error'];
            }
            inv()->flush();
        }
        if (! $added) {
            return back()->with('warn', $skipped ? implode(' ', $skipped) : 'Nothing was added — check the box beside at least one suggested item first.');
        }
        $msg = $added.' item'.($added > 1 ? 's' : '').' added to your Rental List. Nothing has been requested yet.';

        return redirect()->route('cart')->with('success', $msg)->with('warn', $skipped ? 'Not added — '.implode(' ', $skipped) : null);
    }

    public function chat(Request $request)
    {
        $msg = mb_substr((string) $request->input('message', ''), 0, 500);
        if (! setting('aiEnabled', true)) {
            return response()->json(['html' => 'The assistant is currently turned off by the PartyRent team.', 'chips' => []]);
        }

        return response()->json(Ai::chat($msg, $request->user()));
    }
}
