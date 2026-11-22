<?php

namespace App\Http\Controllers;

use App\Domain\Ai;
use App\Domain\Finance;
use App\Domain\Locations;
use App\Domain\Notifier;
use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Review;
use App\Models\Supplier;
use App\Support\RentalPeriod;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    public const EVENT_THEMES = [
        ['Birthday', '🎂', 'linear-gradient(135deg,#F472B6,#E11D74)'], ['Wedding', '💍', 'linear-gradient(135deg,#FBBF24,#B45309)'],
        ['Debut', '👑', 'linear-gradient(135deg,#C084FC,#7C3AED)'], ['Christening', '🕊️', 'linear-gradient(135deg,#67E8F9,#0E7490)'],
        ['Corporate', '🏢', 'linear-gradient(135deg,#60A5FA,#1D4ED8)'], ['Reunion', '👨‍👩‍👧‍👦', 'linear-gradient(135deg,#34D399,#0D9488)'],
    ];

    private function live()
    {
        $list = Product::live()->with(['supplier', 'category'])->get();
        inv()->primeProducts($list);

        return $list;
    }

    /** Old /browse links: same page now lives at "/". */
    public function browseRedirect(Request $request)
    {
        return redirect()->to(route('home', $request->query()), 301);
    }

    /** Home and browse are one page: welcome bits on top, then sticky dates/filters and the results. */
    public function browse(Request $request)
    {
        $u = $request->user();
        if ($u && $u->isStaff()) {
            return redirect()->route($u->homeRoute());
        }
        $st = array_merge(['q' => '', 'category' => '', 'supplier' => '', 'city' => '', 'event' => '', 'color' => '', 'theme' => '', 'min' => '', 'max' => '', 'start' => '', 'end' => '', 'qty' => '', 'avail' => '', 'delivery' => '', 'pickup' => '', 'rating' => '', 'sort' => 'recommended', 'view' => 'items'], array_filter($request->only(['q', 'category', 'supplier', 'city', 'event', 'color', 'theme', 'min', 'max', 'start', 'end', 'qty', 'avail', 'delivery', 'pickup', 'rating', 'sort', 'view']), fn ($v) => $v !== null));
        if ($st['start'] && $st['start'] < today_iso()) {
            $st['start'] = today_iso();
        }
        if ($st['start'] && (! $st['end'] || $st['end'] < $st['start'])) {
            $st['end'] = $st['start'];
        }
        // "Landing" = nothing searched or filtered yet (dates don't count): the home sections show above the results.
        $landing = ! array_filter(array_diff_key($st, ['sort' => 1, 'view' => 1, 'start' => 1, 'end' => 1]), fn ($v) => $v !== '') && $st['sort'] === 'recommended' && $st['view'] === 'items' && ! $request->query('compare');
        // Rental dates: from the link (remembered as the rental period), otherwise the customer's selected period.
        if ($request->boolean('clear')) {
            RentalPeriod::clear();
            $st['start'] = $st['end'] = '';
        } elseif ($st['start'] && ! RentalPeriod::validate($st['start'], $st['end'])) {
            RentalPeriod::set($st['start'], $st['end']);
        } elseif ($rp = RentalPeriod::get()) {
            [$st['start'], $st['end']] = [$rp['start'], $rp['end']];
        }
        $to = null;
        $dates = $st['start'] ? array_slice(date_range($st['start'], $st['end']), 0, (int) setting('maxRentalDays', 30)) : [];
        $need = max(1, (int) $st['qty']);
        $ai = $st['q'] ? Ai::interpret($st['q']) : null;
        $guests = $ai['guests'] ?? null;
        // "for 50 guests" is the event size, not words to match in names
        $qWords = $guests ? preg_replace('/\b\d{1,4}\s*\+?\s*(guests?|pax|people|persons?|bisita|katao|tao|visitors|attendees|heads)\b/iu', ' ', $st['q']) : $st['q'];
        $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower($qWords)) ?: [], fn ($w) => mb_strlen($w) > 1));
        $matchText = fn (string $hay) => ! $words || collect($words)->every(fn ($w) => str_contains($hay, $w)) || ($ai && $ai['items'] && collect($words)->contains(fn ($w) => str_contains($hay, $w)));

        $prods = $this->live();
        $sups = Supplier::approved()->get();

        if ($st['view'] === 'packages') {
            $list = Package::live()->with('supplier')->get()->filter(function ($k) use ($st, $dates, $need, $matchText, $to, $guests) {
                if (\App\Domain\Packages::fitsGuests($k, $guests) === false) return false; // too small for the event
                $s = $k->supplier;
                if (! $matchText(mb_strtolower($k->name.' '.$k->description.' '.$s->name.' '.implode(' ', $k->events ?? [])))) {
                    return false;
                }
                if ($st['event'] && ! in_array($st['event'], $k->events ?? [], true)) return false;
                if ($st['supplier'] && $k->supplier_id != $st['supplier']) return false;
                if ($st['city'] && $s->city !== $st['city']) return false;
                if ($st['min'] !== '' && $k->price < (float) $st['min']) return false;
                if ($st['max'] !== '' && $k->price > (float) $st['max']) return false;
                if ($st['delivery'] && (! $s->offersDelivery() || ($to && ! $s->deliversTo($to)))) return false;
                if ($st['pickup'] && $s->fulfillment === 'delivery') return false;
                if ($st['rating'] && $s->rating < (float) $st['rating']) return false;
                if ($st['category'] && ! collect($k->items)->contains(fn ($it) => inv()->product($it['product_id'])?->category_id === $st['category'])) return false;
                if ($st['avail'] && $dates && collect($dates)->contains(fn ($d) => inv()->packageAvailableOn($k->id, $d) < $need)) return false;

                return true;
            });
            $list = match ($st['sort']) {
                'price_asc' => $list->sortBy('price'), 'price_desc' => $list->sortByDesc('price'),
                'recent' => $list->sortByDesc('created_at'), default => $list->sortByDesc(fn ($k) => $k->supplier->rating),
            };
            // A guest count in the search: packages sized for it first, the closest capacity first.
            if ($guests) {
                [$fit, $rest] = $list->partition(fn ($k) => \App\Domain\Packages::fitsGuests($k, $guests));
                $list = ($st['sort'] === 'recommended' ? $fit->sortBy('max_guests') : $fit)->concat($rest);
            }
        } else {
            $list = $prods->filter(function ($x) use ($st, $dates, $need, $matchText, $ai, $to) {
                $s = $x->supplier;
                $hay = mb_strtolower($x->name.' '.$x->description.' '.$s->name.' '.$s->city.' '.($x->category->name ?? '').' '.implode(' ', $x->colors ?? []).' '.implode(' ', $x->themes ?? []).' '.implode(' ', $x->events ?? []));
                if (! $matchText($hay) && ! ($ai && in_array($x->category_id, $ai['items'], true))) return false;
                if ($st['category'] && $x->category_id !== $st['category']) return false;
                if ($st['supplier'] && $x->supplier_id != $st['supplier']) return false;
                if ($st['city'] && $s->city !== $st['city']) return false;
                if ($st['event'] && ! in_array($st['event'], $x->events ?? [], true)) return false;
                if ($st['color'] && ! in_array($st['color'], $x->colors ?? [], true)) return false;
                if ($st['theme'] && ! in_array($st['theme'], $x->themes ?? [], true)) return false;
                $price = $x->basePrice();
                if ($st['min'] !== '' && $price < (float) $st['min']) return false;
                if ($st['max'] !== '' && $price > (float) $st['max']) return false;
                if ($st['delivery'] && (! $s->offersDelivery() || ! $x->delivery || ($to && ! $s->deliversTo($to)))) return false;
                if ($st['pickup'] && $s->fulfillment === 'delivery') return false;
                if ($st['rating'] && $s->rating < (float) $st['rating']) return false;
                if ($st['avail'] && inv()->minAvailable($x->id, $dates) < $need) return false;

                return true;
            });
            $score = fn ($x) => $x->rating * 2 + log10(1 + $x->rent_count) + ($ai && array_intersect($ai['colors'], $x->colors ?? []) ? 2 : 0) + (inv()->minAvailable($x->id, $dates) >= $need ? 2 : -3);
            $list = match ($st['sort']) {
                'price_asc' => $list->sortBy(fn ($x) => $x->basePrice()), 'price_desc' => $list->sortByDesc(fn ($x) => $x->basePrice()),
                'popular' => $list->sortByDesc('rent_count'), 'recent' => $list->sortByDesc('created_at'),
                'rating' => $list->sortBy([['rating', 'desc'], ['rent_count', 'desc']]), default => $list->sortByDesc($score),
            };
            // With rental dates, whatever the sort, items free on those dates come first.
            if ($dates) {
                [$free, $busy] = $list->partition(fn ($x) => $this->freeOn($x, $dates, $need));
                $list = $free->concat($busy);
            }
        }

        $compare = collect();
        if ($ids = array_slice((array) $request->query('compare', []), 0, 3)) {
            $compare = $prods->whereIn('id', array_map('intval', $ids))->values();
        }
        $cat = $st['category'] ? Category::find($st['category']) : null;
        // Home sections (reference C.home), only on the landing view.
        $home = [];
        if ($landing) {
            $evs = $u ? RentalRequest::where('customer_id', $u->id)->pluck('event_type')->unique()->all() : [];
            $rec = $evs ? $prods->filter(fn ($p) => array_intersect($p->events ?? [], $evs))->sortByDesc('rent_count')->take(4) : collect();
            $home = [
                'featured' => $prods->sortBy([['rating', 'desc'], ['rent_count', 'desc']])->take(8),
                'recommended' => $rec->count() ? $rec : $prods->sortByDesc('rent_count')->take(4),
                'offers' => $prods->filter(fn ($p) => count($p->tiers ?? []) > 1)->sortByDesc(fn ($p) => 1 - \App\Domain\Pricing::lowestPrice($p) / max(0.01, $p->basePrice()))->take(4),
                'recent' => $prods->sortByDesc('created_at')->take(4),
                'topSuppliers' => $sups->sortByDesc('rating')->take(3),
            ];
        }
        $pkgs = $landing ? Package::live()->with('supplier')->get() : collect();
        // Rental dates picked (post-login prompt or the date bar): suggest what is actually free on every one of those days.
        $forDates = null;
        if ($dates && $landing) {
            $evs ??= $u ? RentalRequest::where('customer_id', $u->id)->pluck('event_type')->unique()->all() : [];
            $free = $prods->filter(fn ($x) => $this->freeOn($x, $dates, $need));
            $forDates = [
                'count' => $free->count(),
                'items' => $free->sortByDesc(fn ($x) => $x->rating * 2 + log10(1 + $x->rent_count) + (array_intersect($x->events ?? [], $evs) ? 3 : 0))->take(8)->values(),
                'packages' => $pkgs->filter(fn ($k) => collect($dates)->every(fn ($d) => inv()->packageAvailableOn($k->id, $d) >= $need))
                    ->sortByDesc(fn ($k) => $k->supplier->rating)->take(4)->values(),
            ];
        }
        $active = $u && $landing ? RentalRequest::where('customer_id', $u->id)->whereIn('status', array_merge(Finance::ACTIVE, ['pending']))->with(['payments', 'supplier'])->orderBy('start_date')->take(3)->get() : collect();
        $notifs = $u && $landing ? Notifier::for($u, session('mode'))->filter(fn ($n) => ! $n->isReadBy($u->id))->take(3) : collect();

        return view('market.browse', [
            'landing' => $landing, 'home' => $home, 'forDates' => $forDates, 'pkgs' => $pkgs, 'pkgCount' => Package::live()->count(), 'active' => $active, 'notifs' => $notifs, 'themes' => self::EVENT_THEMES,
            'prodCount' => $prods->count(), 'supCount' => $sups->count(),
            'st' => $st, 'list' => $list->values(), 'dates' => $dates, 'ai' => $ai, 'compare' => $compare,
            'cats' => Category::where('active', true)->orderBy('sort')->get(), 'sups' => $sups,
            'colors' => $prods->flatMap(fn ($x) => $x->colors ?? [])->unique()->sort()->values(),
            'themesList' => $prods->flatMap(fn ($x) => $x->themes ?? [])->unique()->sort()->values(),
            'events' => array_keys(Ai::EVENTS), 'cities' => $sups->pluck('city')->unique()->values(),
            'heading' => $st['q'] ? 'Results for “'.$st['q'].'”' : ($cat ? $cat->name : ($st['event'] ? $st['event'].' supplies' : ($st['view'] === 'packages' ? 'Rental packages' : ($landing ? 'All party rentals' : 'Browse party supplies')))),
            'cat' => $cat, 'to' => $to,
        ]);
    }

    /** Can this item be rented for every one of these dates (enough stock each day, sale items past their lead time)? */
    private function freeOn(Product $x, array $dates, int $need): bool
    {
        if ($x->isSale() && $dates[0] < add_days(today_iso(), (int) $x->lead_days)) {
            return false;
        }

        return inv()->minAvailable($x->id, $dates) >= $need;
    }

    public function suppliers(Request $request)
    {
        $q = mb_strtolower((string) $request->query('q', ''));
        $f = (string) $request->query('f', '');
        $list = Supplier::approved()->with(['products' => fn ($p) => $p->where('status', 'active')->where('package_only', false)->with('category')])->withCount(['packages' => fn ($k) => $k->where('active', true)])->orderByDesc('rating')->get()
            ->filter(fn ($s) => (! $q || str_contains(mb_strtolower($s->name.' '.$s->city.' '.$s->description), $q)) && (! $f || $s->isOrganization() || $s->fulfillment === 'both' || $s->fulfillment === $f));
        // rental shops (1-to-1 item rentals) and event companies (book a whole event by quotation)
        $type = in_array($request->query('type'), ['shops', 'business'], true) ? $request->query('type') : '';
        $list = $list->filter(fn ($s) => ! $type || ($type === 'business') === $s->isOrganization());

        return view('market.suppliers', ['list' => $list, 'q' => $request->query('q', ''), 'f' => $f, 'type' => $type]);
    }

    public function supplier(Request $request, Supplier $supplier)
    {
        $own = $request->user() && $supplier->owner_id === $request->user()->id;
        abort_unless($supplier->status === 'approved' || $own, 404);
        $items = $supplier->products()->where('status', 'active')->where('package_only', false)->with(['supplier', 'category'])->get();
        inv()->primeProducts($items);

        return view('market.supplier', [
            's' => $supplier, 'items' => $items, 'own' => $own,
            'pkgs' => $supplier->packages()->where('active', true)->where('is_draft', false)->orderBy('sort')->with('supplier')->get(),
            'reviews' => Review::visible()->where('supplier_id', $supplier->id)->with(['customer', 'product', 'package'])->latest()->get(),
            'tab' => $request->query('tab', $supplier->isOrganization() ? 'packages' : 'items'), 'catFilter' => $request->query('category'),
        ]);
    }

    public function product(Request $request, Product $product)
    {
        abort_unless($product->status === 'active' && ! $product->package_only && $product->supplier->status === 'approved', 404);
        $today = today_iso();
        $earliest = add_days($today, $product->isSale() ? (int) $product->lead_days : 0);
        // dates from the link, else the rental period the customer already chose (browse bar / checkout), else a week ahead
        $rp = rental_period();
        $start = $request->query('start') && $request->query('start') >= $earliest ? $request->query('start') : ($rp && $rp['start'] >= $earliest ? $rp['start'] : max(add_days($today, 7), $earliest));
        $end = $request->query('end') && $request->query('end') >= $start ? $request->query('end') : (! $request->query('start') && $rp && $rp['start'] === $start ? $rp['end'] : $start);
        // The customer picks how many; only a quantity they already chose (browse filter, rental list) is filled in.
        $qtyPicked = (int) $request->query('qty') > 0;
        $qty = $qtyPicked ? (int) $request->query('qty') : 1;
        $live = $this->live();
        $u = $request->user();

        return view('market.product', [
            'p' => $product, 'sup' => $product->supplier, 'cat' => $product->category,
            'start' => $start, 'end' => $end, 'qty' => $qtyPicked ? $qty : null,
            'days' => array_fill_keys(date_range($start, $end), $qty),
            'reviews' => $product->reviews()->visible()->with(['customer', 'product', 'supplier'])->latest()->get(),
            'related' => $live->where('supplier_id', $product->supplier_id)->where('id', '!=', $product->id)->take(4),
            'similar' => $live->where('category_id', $product->category_id)->where('id', '!=', $product->id)->sortByDesc('rating')->take(4),
            'own' => $u && $product->supplier->owner_id === $u->id,
            'siblings' => $u ? \App\Domain\Rentals::cartLinesFor($u, $product->supplier_id) : [],
        ]);
    }

    /** HTML snippet: AI-suggested alternatives when the item can't cover the requested days. */
    public function alternatives(Request $request, Product $product)
    {
        $days = \App\Domain\Rentals::cleanDays((array) json_decode((string) $request->query('days', '{}'), true));
        $alts = $days ? Ai::alternatives($product, $days) : [];
        $dates = array_keys($days);

        return view('market._alternatives', ['alts' => $alts, 'dates' => $dates, 'maxQty' => $days ? max($days) : 1]);
    }

    public function packages(Request $request)
    {
        $all = Package::live()->with('supplier')->get();
        $ev = (string) $request->query('event', '');

        return view('market.packages', [
            'list' => $ev ? $all->filter(fn ($k) => in_array($ev, $k->events ?? [], true)) : $all,
            'events' => $all->flatMap(fn ($k) => $k->events ?? [])->unique()->values(), 'ev' => $ev,
        ]);
    }

    public function package(Request $request, Package $package)
    {
        abort_unless($package->active && ! $package->is_draft && $package->supplier->status === 'approved', 404);
        $def = \App\Domain\Packages::def($package);
        $pp = \App\Domain\Packages::price($def);
        $lead = \App\Domain\Packages::leadDays($def, $pp['sel']);
        $today = today_iso();
        $earliest = add_days($today, $lead);
        // dates from the link, else the rental period the customer already chose (browse bar / checkout), else a week ahead
        $rp = rental_period();
        $start = $request->query('start') && $request->query('start') >= $earliest ? $request->query('start') : ($rp && $rp['start'] >= $earliest ? $rp['start'] : max(add_days($today, 7), $earliest));
        $end = $request->query('end') && $request->query('end') >= $start ? $request->query('end') : (! $request->query('start') && $rp && $rp['start'] === $start ? $rp['end'] : $start);
        $u = $request->user();

        return view('market.package', [
            'k' => $package, 'sup' => $package->supplier, 'def' => $def, 'pp' => $pp, 'lead' => $lead,
            'start' => $start, 'end' => $end, 'days' => array_fill_keys(date_range($start, $end), 1),
            'own' => $u && $package->supplier->owner_id === $u->id,
            'reviews' => $package->reviews()->visible()->with(['customer', 'package', 'supplier'])->latest()->get(),
            'siblings' => $u ? \App\Domain\Rentals::cartLinesFor($u, $package->supplier_id) : [],
        ]);
    }
}
