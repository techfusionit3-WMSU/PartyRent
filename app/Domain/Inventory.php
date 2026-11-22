<?php

namespace App\Domain;

use App\Models\Hold;
use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;

/**
 * THE single source of truth for date-, time- and location-based availability
 * (port of reference js/core/inventory.js, v4).
 *
 *   Available(date, time) = Total − Peak reserved during the overlapping time
 *                           − Damaged − Missing − Written-off − Maintenance(date) − SetAside(date)
 *   Peak reserved = the HIGHEST combined quantity of accepted bookings that overlap the requested time
 *     (including the supplier's preparation and recovery buffers) — not the sum of every booking on that
 *     date. A morning booking never reduces afternoon stock unless they overlap.
 *   Location: a product has one quantity per inventory location (sum = total). A line with a source
 *     location only uses that location's stock.
 *   Pending requests never consume stock; they are reported as potential conflicts.
 *
 * App additions kept: sale (consumable) items are not date-based (StockOnHand − Committed); package
 * lines expand through App\Domain\Packages; services with a daily team limit are checked too.
 *
 * Registered as a singleton (memoized per HTTP request). Call flush() after writes.
 */
class Inventory
{
    public const RESERVING = RentalRequest::RESERVING;

    /** Accepted but not yet released: these requests hold (commit) sale stock. */
    public const COMMITTING = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'];

    private array $products = [];

    private array $packages = [];

    private array $suppliers = [];

    private ?array $holds = null;

    /** @var array<int, array{id:int, status:string, lines:array, sale:array, svc:array}>|null */
    private ?array $demands = null;

    /** @var array<int, array>|null product id => intervals of open requests (see intervalIndex) */
    private ?array $ivIndex = null;

    /** Memoized breakdown() results for this request. */
    private array $bd = [];

    private array $caps = [];

    private array $locStock = [];

    /** Shops whose products are all loaded (product()). */
    private array $shopsPrimed = [];

    /** Running discounts per shop and day (Discounts::running). */
    private array $disc = [];

    public function flush(): void
    {
        $this->products = $this->packages = $this->suppliers = $this->caps = $this->locStock = $this->disc = $this->shopsPrimed = [];
        $this->holds = $this->demands = $this->ivIndex = null;
        $this->bd = [];
    }

    /* ---------------- lookups ---------------- */

    public function product(int|string|null $id): ?Product
    {
        if (! $id) {
            return null;
        }
        $id = (int) $id;
        if (! array_key_exists($id, $this->products)) {
            $this->products[$id] = Product::find($id);
            // pages look at many items of the same shop (lines, packages, dashboards): load the rest of that shop in
            // one query instead of one query per item
            $sid = $this->products[$id]?->supplier_id;
            if ($sid && ! isset($this->shopsPrimed[$sid])) {
                $this->shopsPrimed[$sid] = true;
                foreach (Product::where('supplier_id', $sid)->where('id', '!=', $id)->get() as $p) {
                    $this->products[$p->id] ??= $p;
                }
            }
        }

        return $this->products[$id];
    }

    public function primeProducts(iterable $products): void
    {
        foreach ($products as $p) {
            $this->products[$p->id] = $p;
        }
    }

    public function package(int|string|null $id): ?Package
    {
        if (! $id) {
            return null;
        }
        $id = (int) $id;
        if (! array_key_exists($id, $this->packages)) {
            $this->packages[$id] = Package::find($id);
        }

        return $this->packages[$id];
    }

    /** A shop, memoized for this request. */
    public function shop(?int $id): ?Supplier
    {
        return $this->supplier($id);
    }

    /** Discounts of a shop running on $at, memoized for this request (a cart / checkout asks once per line). */
    public function runningDiscounts(int $supplierId, string $at, \Closure $load): \Illuminate\Support\Collection
    {
        return $this->disc[$supplierId.'|'.$at] ??= $load();
    }

    private function supplier(?int $id): ?Supplier
    {
        if (! $id) {
            return null;
        }
        if (! array_key_exists($id, $this->suppliers)) {
            $this->suppliers[$id] = Supplier::find($id);
        }

        return $this->suppliers[$id];
    }

    public function itemName(array $line): string
    {
        if (($line['type'] ?? 'item') === 'package') {
            return $this->package($line['ref_id'])?->name ?? 'Package';
        }

        return $this->product($line['ref_id'])?->name ?? 'Item';
    }

    /** Package definition for a line: the snapshot frozen at booking, else the live package. */
    public function lineDef(array $line): ?array
    {
        if (! empty($line['snap'])) {
            return Packages::def($line['snap']);
        }
        $k = $this->package($line['ref_id']);

        return $k ? Packages::def($k) : null;
    }

    /* ---------------- units ---------------- */

    /** RENTAL units [productId => qty] of a line on one date (packages expand into their components). */
    public function unitsForLine(array $line, string $date): array
    {
        $q = (int) ($line['days'][$date] ?? 0);
        if (! $q) {
            return [];
        }
        if (($line['type'] ?? 'item') === 'package') {
            $def = $this->lineDef($line);

            return $def ? array_map(fn ($n) => $n * $q, Packages::units($def, $line['sel'] ?? null)['rent']) : [];
        }

        return $this->product($line['ref_id'])?->isSale() ? [] : [(int) $line['ref_id'] => $q];
    }

    /** SALE units of a line: an item's quantities add up across dates; a package counts its peak day. */
    public function saleUnitsForLine(array $line): array
    {
        $qs = array_filter(array_map('intval', $line['days'] ?? []));
        if (! $qs) {
            return [];
        }
        if (($line['type'] ?? 'item') === 'package') {
            $def = $this->lineDef($line);

            return $def ? array_map(fn ($n) => $n * max($qs), Packages::units($def, $line['sel'] ?? null)['sale']) : [];
        }

        return $this->product($line['ref_id'])?->isSale() ? [(int) $line['ref_id'] => array_sum($qs)] : [];
    }

    public function saleDemand(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            foreach ($this->saleUnitsForLine($l) as $pid => $q) {
                $out[$pid] = ($out[$pid] ?? 0) + $q;
            }
        }

        return $out;
    }

    /** Whole-day totals [date => [productId => qty]] (summaries). */
    public function demandMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            foreach (array_keys($line['days'] ?? []) as $date) {
                foreach ($this->unitsForLine($line, $date) as $pid => $q) {
                    $map[$date][$pid] = ($map[$date][$pid] ?? 0) + $q;
                }
            }
        }
        ksort($map);

        return $map;
    }

    /* ---------------- time intervals ---------------- */

    /** [prep, recovery] minutes of the supplier that owns the line's item. */
    public function bufFor(array $line): array
    {
        $item = ($line['type'] ?? 'item') === 'package' ? $this->package($line['ref_id']) : $this->product($line['ref_id']);
        $st = Slots::settings($this->supplier($item?->supplier_id));

        return [(int) round(((float) $st['prepHours']) * 60), (int) round(((float) $st['recoveryHours']) * 60)];
    }

    /** Occupied intervals of a line for ONE product (units), with buffers. */
    public function productIntervals(array $line, int $pid): array
    {
        $out = [];
        foreach (Slots::lineIntervals($line, $this->bufFor($line)) as $iv) {
            $iv['qty'] = $this->unitsForLine($line, $iv['date'])[$pid] ?? 0;
            if ($iv['qty'] > 0) {
                $out[] = $iv;
            }
        }

        return $out;
    }

    /** Occupied window [from, to] (minutes of the day, may pass 0/1440 with buffers) of a line on a date. */
    public function lineWindow(array $line, string $date): ?array
    {
        foreach (Slots::lineIntervals($line, $this->bufFor($line)) as $iv) {
            if ($iv['date'] === $date) {
                $base = Slots::dayNum($date) * Slots::DAY;

                return [$iv['from'] - $base, $iv['to'] - $base];
            }
        }

        return null;
    }

    private function absWindow(string $date, ?array $win): array
    {
        $base = Slots::dayNum($date) * Slots::DAY;

        return $win ? [$base + $win[0], $base + $win[1]] : [$base, $base + Slots::DAY];
    }

    /** Open requests (reserving + pending), loaded once per HTTP request. */
    private function openDemands(): array
    {
        if ($this->demands === null) {
            $this->demands = [];
            foreach (RentalRequest::whereIn('status', array_merge(self::RESERVING, ['pending']))->get(['id', 'code', 'status', 'lines', 'reserved_at']) as $r) {
                $lines = $r->lines ?? [];
                // accepted but the down payment is not verified yet: holds nothing, counted like a pending request
                $this->demands[] = ['id' => $r->id, 'code' => $r->code, 'status' => $r->holdsStock() || $r->status !== 'accepted' ? $r->status : 'pending', 'lines' => $lines, 'sale' => $this->saleDemand($lines), 'svc' => $this->serviceDemand($lines)];
            }
        }

        return $this->demands;
    }

    /**
     * Every open request's occupied intervals, indexed by product: [pid => [interval + request info]].
     * Built once per HTTP request (each line is expanded once for all its products), so availability
     * lookups stay fast as bookings pile up instead of re-walking every request for every product/date.
     */
    private function intervalIndex(): array
    {
        if ($this->ivIndex === null) {
            $this->ivIndex = [];
            foreach ($this->openDemands() as $d) {
                foreach ($d['lines'] as $l) {
                    $meta = ['requestId' => $d['id'], 'code' => $d['code'], 'status' => $d['status'], 'sourceId' => isset($l['sourceId']) ? (int) $l['sourceId'] : null];
                    foreach (Slots::lineIntervals($l, $this->bufFor($l)) as $iv) {
                        foreach ($this->unitsForLine($l, $iv['date']) as $pid => $qty) {
                            if ($qty > 0) {
                                $this->ivIndex[$pid][] = array_merge($iv, ['qty' => $qty]) + $meta;
                            }
                        }
                    }
                }
            }
        }

        return $this->ivIndex;
    }

    /** Intervals of existing requests in the given statuses (optionally only one source location). */
    public function existingIntervals(int $pid, array $statuses, ?int $excludeId = null, ?int $locId = null): array
    {
        $out = [];
        foreach ($this->intervalIndex()[$pid] ?? [] as $iv) {
            if ($iv['requestId'] === $excludeId || ! in_array($iv['status'], $statuses, true) || ($locId && (int) ($iv['sourceId'] ?? 0) !== $locId)) {
                continue;
            }
            $out[] = $iv;
        }

        return $out;
    }

    /** Peak demand of one product on a date (within a time window), plus the requests involved. */
    public function demandOn(int $pid, string $date, array $statuses, ?int $excludeId = null, ?array $win = null, ?int $locId = null): array
    {
        $key = $pid.'|'.$date.'|'.implode(',', $statuses).'|'.$excludeId.'|'.($win ? implode('-', $win) : '').'|'.$locId;

        return $this->bd[$key] ??= $this->computeDemandOn($pid, $date, $statuses, $excludeId, $win, $locId);
    }

    private function computeDemandOn(int $pid, string $date, array $statuses, ?int $excludeId, ?array $win, ?int $locId): array
    {
        [$A, $B] = $this->absWindow($date, $win);
        $ivs = array_values(array_filter($this->existingIntervals($pid, $statuses, $excludeId, $locId), fn ($iv) => $iv['from'] < $B && $iv['to'] > $A));
        $byReq = [];
        foreach ($ivs as $iv) {
            $k = $iv['requestId'];
            $byReq[$k] ??= ['request_id' => $k, 'code' => $iv['code'], 'qty' => 0, 'status' => $iv['status'], 'from' => $iv['rawFrom'], 'to' => $iv['rawTo']];
            $byReq[$k]['qty'] = max($byReq[$k]['qty'], $iv['qty']);
        }

        return ['total' => Slots::peak($ivs, $A, $B), 'refs' => array_values($byReq)];
    }

    public function reservedOn(int $pid, string $date, ?int $excludeId = null, ?array $win = null): int
    {
        return $this->demandOn($pid, $date, self::RESERVING, $excludeId, $win)['total'];
    }

    public function pendingOn(int $pid, string $date, ?int $excludeId = null, ?array $win = null): int
    {
        return $this->demandOn($pid, $date, ['pending'], $excludeId, $win)['total'];
    }

    /* ---------------- sale items ---------------- */

    public function committedSale(int $pid, ?int $excludeId = null): int
    {
        $t = 0;
        foreach ($this->openDemands() as $d) {
            if ($d['id'] !== $excludeId && in_array($d['status'], self::COMMITTING, true)) {
                $t += $d['sale'][$pid] ?? 0;
            }
        }

        return $t;
    }

    public function pendingSale(int $pid, ?int $excludeId = null): int
    {
        $t = 0;
        foreach ($this->openDemands() as $d) {
            if ($d['id'] !== $excludeId && $d['status'] === 'pending') {
                $t += $d['sale'][$pid] ?? 0;
            }
        }

        return $t;
    }

    public function saleAvailable(int $pid, ?int $excludeId = null): int
    {
        $p = $this->product($pid);

        return $p ? max(0, $p->stockOnHand() - $this->committedSale($pid, $excludeId)) : 0;
    }

    /* ---------------- services with a daily team limit ---------------- */

    public function serviceDemand(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (($l['type'] ?? '') !== 'package' || ! ($def = $this->lineDef($l))) {
                continue;
            }
            $sid = $this->package($l['ref_id'])?->supplier_id;
            foreach (Packages::limitedServices($def) as $k => [$name, $qty]) {
                foreach (array_filter(array_map('intval', $l['days'] ?? [])) as $date => $n) {
                    $key = $sid.'|'.$k;
                    $out[$date][$key] = [$name, ($out[$date][$key][1] ?? 0) + $qty * $n];
                }
            }
        }

        return $out;
    }

    public function serviceCap(string $key): ?int
    {
        if (! array_key_exists($key, $this->caps)) {
            [$sid, $name] = explode('|', $key, 2);
            $cap = null;
            foreach (Package::where('supplier_id', (int) $sid)->get() as $k) {
                $t = Packages::limitedServices(Packages::def($k))[$name][2] ?? null;
                if ($t !== null) {
                    $cap = $cap === null ? $t : min($cap, $t);
                }
            }
            $this->caps[$key] = $cap;
        }

        return $this->caps[$key];
    }

    /* ---------------- holds ---------------- */

    private function holdsFor(int $pid): array
    {
        if ($this->holds === null) {
            $this->holds = [];
            foreach (Hold::all() as $h) {
                $this->holds[$h->product_id][] = $h;
            }
        }

        return $this->holds[$pid] ?? [];
    }

    /** @return array{maintenance:int, setaside:int, list:array} */
    public function holdsOn(int $pid, string $date): array
    {
        $out = ['maintenance' => 0, 'setaside' => 0, 'list' => []];
        foreach ($this->holdsFor($pid) as $h) {
            if ($date < $h->start_date || ($h->end_date && $date > $h->end_date)) {
                continue;
            }
            $out[$h->type === 'setaside' ? 'setaside' : 'maintenance'] += $h->qty;
            $out['list'][] = $h;
        }

        return $out;
    }

    public function unusableOn(Product $p, string $date): int
    {
        $h = $this->holdsOn($p->id, $date);

        return (int) $p->damaged + (int) $p->missing + (int) $p->written_off + $h['maintenance'] + $h['setaside'];
    }

    /* ---------------- availability ---------------- */

    /** Full breakdown for one product on one date ($win = time window [from, to] in minutes of the day). */
    public function breakdown(int $pid, string $date, ?int $excludeId = null, ?array $win = null): ?array
    {
        $p = $this->product($pid);
        if (! $p) {
            return null;
        }
        if ($p->isSale()) {
            $committed = $this->committedSale($pid, $excludeId);

            return [
                'date' => $date, 'total' => $p->stockOnHand(), 'sale' => true,
                'reserved' => $committed, 'reservedRefs' => [], 'pending' => $this->pendingSale($pid, $excludeId), 'pendingRefs' => [],
                'damaged' => 0, 'missing' => 0, 'writtenOff' => 0, 'maintenance' => 0, 'setaside' => 0, 'holds' => [],
                'available' => max(0, $p->stockOnHand() - $committed),
            ];
        }
        $res = $this->demandOn($pid, $date, self::RESERVING, $excludeId, $win);
        $pen = $this->demandOn($pid, $date, ['pending'], $excludeId, $win);
        $h = $this->holdsOn($pid, $date);

        return [
            'date' => $date, 'total' => (int) $p->total_stock,
            'reserved' => $res['total'], 'reservedRefs' => $res['refs'],
            'pending' => $pen['total'], 'pendingRefs' => $pen['refs'],
            'damaged' => (int) $p->damaged, 'missing' => (int) $p->missing, 'writtenOff' => (int) $p->written_off,
            'maintenance' => $h['maintenance'], 'setaside' => $h['setaside'], 'holds' => $h['list'],
            'available' => max(0, $p->total_stock - $res['total'] - $this->unusableOn($p, $date)),
        ];
    }

    public function availableOn(int $pid, string $date, ?int $excludeId = null, ?array $win = null): int
    {
        return $this->breakdown($pid, $date, $excludeId, $win)['available'] ?? 0;
    }

    /** Units of an item free for a time window (minutes of the day) on a date (hourly booking API). */
    public function availableAt(int $pid, string $date, int $from, int $to, ?int $excludeId = null): int
    {
        return $this->availableOn($pid, $date, $excludeId, [$from, $to]);
    }

    /** Booked windows of an item on a date (booked requests only), for customers picking a time. */
    public function busySlots(int $pid, string $date): array
    {
        $base = Slots::dayNum($date) * Slots::DAY;
        $out = [];
        foreach ($this->existingIntervals($pid, self::RESERVING) as $iv) {
            if ($iv['date'] === $date) {
                $out[] = ['from' => max(0, $iv['rawFrom'] - $base), 'to' => min(Slots::DAY, $iv['rawTo'] - $base), 'qty' => $iv['qty']];
            }
        }
        usort($out, fn ($a, $b) => $a['from'] <=> $b['from']);

        return $out;
    }

    /* ---------------- inventory locations ---------------- */

    /** [locationId => qty] of a product. */
    public function stockByLocation(int $pid): array
    {
        if (! array_key_exists($pid, $this->locStock)) {
            $this->locStock[$pid] = \App\Models\LocationStock::where('product_id', $pid)->pluck('qty', 'inventory_location_id')->map(fn ($q) => (int) $q)->all();
        }

        return $this->locStock[$pid];
    }

    public function stockAt(int $pid, int $locId): int
    {
        return $this->stockByLocation($pid)[$locId] ?? 0;
    }

    /** Available at one location = min(location stock − peak bookings from that location, product-wide availability). */
    public function availableAtLocation(int $pid, int $locId, string $date, ?int $excludeId = null, ?array $win = null): int
    {
        $locPeak = $this->demandOn($pid, $date, self::RESERVING, $excludeId, $win, $locId)['total'];

        return max(0, min($this->stockAt($pid, $locId) - $locPeak, $this->availableOn($pid, $date, $excludeId, $win)));
    }

    /** Per-location view of one product on a date. */
    public function byLocation(int $pid, string $date, ?int $excludeId = null, ?array $win = null): array
    {
        $p = $this->product($pid);
        if (! $p) {
            return [];
        }

        return $this->supplier($p->supplier_id)?->locations->map(fn ($loc) => [
            'loc' => $loc, 'stock' => $this->stockAt($pid, $loc->id),
            'reserved' => $this->demandOn($pid, $date, self::RESERVING, $excludeId, $win, $loc->id)['total'],
            'available' => $this->availableAtLocation($pid, $loc->id, $date, $excludeId, $win),
        ])->all() ?? [];
    }

    /* ---------------- packages & periods ---------------- */

    /** Whole packages bookable on a date (limited by the scarcest rent or sale component). */
    public function packageAvailableOn(int $pkgId, string $date, ?int $excludeId = null, ?array $sel = null, ?array $def = null, ?array $win = null): int
    {
        $def ??= ($k = $this->package($pkgId)) ? Packages::def($k) : null;
        if (! $def) {
            return 0;
        }
        $u = Packages::units($def, $sel);
        $min = PHP_INT_MAX;
        foreach ($u['rent'] as $pid => $q) {
            $min = min($min, intdiv($this->availableOn($pid, $date, $excludeId, $win), max(1, $q)));
        }
        foreach ($u['sale'] as $pid => $q) {
            $min = min($min, intdiv($this->saleAvailable($pid, $excludeId), max(1, $q)));
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /** Availability over a period = the lowest daily figure (today when no dates). */
    public function minAvailable(int $pid, array $dates, ?int $excludeId = null): int
    {
        if (! $dates) {
            return $this->availableOn($pid, today_iso(), $excludeId);
        }

        return min(array_map(fn ($d) => $this->availableOn($pid, $d, $excludeId), $dates));
    }

    public function packageMinAvailable(int $pkgId, array $dates, ?array $sel = null): int
    {
        if (! $dates) {
            return $this->packageAvailableOn($pkgId, today_iso(), null, $sel);
        }

        return min(array_map(fn ($d) => $this->packageAvailableOn($pkgId, $d, null, $sel), $dates));
    }

    /** Availability of a line over its own dates and time window. */
    public function lineAvailable(array $line, ?int $excludeId = null): int
    {
        $dates = array_keys(array_filter($line['days'] ?? [], fn ($q) => (int) $q > 0));
        if (! $dates) {
            return 0;
        }

        return min(array_map(fn ($d) => ($line['type'] ?? 'item') === 'package'
            ? $this->packageAvailableOn((int) $line['ref_id'], $d, $excludeId, $line['sel'] ?? null, null, $this->lineWindow($line, $d))
            : $this->availableOn((int) $line['ref_id'], $d, $excludeId, $this->lineWindow($line, $d)), $dates));
    }

    /* ---------------- check ---------------- */

    /**
     * Validate lines against stock, date by date and time by time. Lines for the same product (also via
     * packages) are combined; overlapping times add up, non-overlapping times share the same units. Lines
     * with a sourceId are also checked against that inventory location's stock.
     *
     * @return array{ok:bool, rows:array, bad:array, byLine:array}
     */
    public function check(array $lines, ?int $excludeId = null): array
    {
        $cand = [];
        foreach ($lines as $l) {
            $pids = [];
            foreach (array_keys($l['days'] ?? []) as $d) {
                foreach (array_keys($this->unitsForLine($l, $d)) as $pid) {
                    $pids[$pid] = true;
                }
            }
            foreach (array_keys($pids) as $pid) {
                foreach ($this->productIntervals($l, $pid) as $iv) {
                    $cand[$pid][] = $iv + ['lineId' => $l['id'] ?? null, 'sourceId' => isset($l['sourceId']) && $l['sourceId'] ? (int) $l['sourceId'] : null];
                }
            }
        }
        $over = fn (array $list, array $c) => array_values(array_filter($list, fn ($x) => $x['from'] < $c['to'] && $x['to'] > $c['from']));
        $rows = [];
        foreach ($cand as $pid => $civs) {
            $p = $this->product($pid);
            if (! $p) {
                continue;
            }
            $exist = $this->existingIntervals($pid, self::RESERVING, $excludeId);
            $all = array_merge($exist, $civs);
            $dates = array_unique(array_column($civs, 'date'));
            sort($dates);
            foreach ($dates as $date) {
                $mine = array_filter($civs, fn ($c) => $c['date'] === $date);
                $cap = $p->total_stock - $this->unusableOn($p, $date);
                $ok = true;
                $req = 0;
                $avail = PHP_INT_MAX;
                foreach ($mine as $c) {
                    if (Slots::peak($over($all, $c), $c['from'], $c['to']) > $cap) {
                        $ok = false;
                    }
                    $req = max($req, Slots::peak($over($civs, $c), $c['from'], $c['to']));
                    $avail = min($avail, max(0, $cap - Slots::peak($over($exist, $c), $c['from'], $c['to'])));
                }
                $rows[] = ['date' => $date, 'product_id' => $pid, 'name' => $p->name, 'requested' => $req, 'available' => $avail === PHP_INT_MAX ? 0 : $avail, 'ok' => $ok];
                // location-level check for lines already assigned to an inventory source
                foreach (array_unique(array_filter(array_column($mine, 'sourceId'))) as $loc) {
                    $exL = array_values(array_filter($exist, fn ($x) => $x['sourceId'] === $loc));
                    $cL = array_values(array_filter($civs, fn ($x) => $x['sourceId'] === $loc));
                    $allL = array_merge($exL, $cL);
                    $capL = $this->stockAt($pid, $loc);
                    $okL = true;
                    $reqL = 0;
                    $avL = PHP_INT_MAX;
                    foreach (array_filter($mine, fn ($c) => $c['sourceId'] === $loc) as $c) {
                        if (Slots::peak($over($allL, $c), $c['from'], $c['to']) > $capL) {
                            $okL = false;
                        }
                        $reqL = max($reqL, Slots::peak($over($cL, $c), $c['from'], $c['to']));
                        $avL = min($avL, max(0, $capL - Slots::peak($over($exL, $c), $c['from'], $c['to'])));
                    }
                    if (! $okL) {
                        $rows[] = ['date' => $date, 'product_id' => $pid, 'name' => $p->name, 'location_id' => $loc, 'requested' => $reqL, 'available' => $avL === PHP_INT_MAX ? 0 : $avL, 'ok' => false];
                    }
                }
            }
        }

        // Sale items: the total across all lines against stock on hand (shown on the first date they are needed).
        $firstDate = collect($lines)->flatMap(fn ($l) => array_keys(array_filter($l['days'] ?? [])))->min();
        $badSale = [];
        foreach ($this->saleDemand($lines) as $pid => $requested) {
            $available = $this->saleAvailable($pid, $excludeId);
            $rows[] = ['date' => $firstDate, 'product_id' => $pid, 'name' => $this->product($pid)?->name ?? 'Item', 'requested' => $requested, 'available' => $available, 'ok' => $requested <= $available, 'sale' => true];
            if ($requested > $available) {
                $badSale[$pid] = true;
            }
        }
        // Services with a daily team limit, counted across every booked request.
        $badSvc = [];
        foreach ($this->serviceDemand($lines) as $date => $svcs) {
            foreach ($svcs as $key => [$name, $q]) {
                if (($cap = $this->serviceCap($key)) === null) {
                    continue;
                }
                $used = 0;
                foreach ($this->openDemands() as $d) {
                    if ($d['id'] !== $excludeId && in_array($d['status'], self::RESERVING, true)) {
                        $used += $d['svc'][$date][$key][1] ?? 0;
                    }
                }
                $free = max(0, $cap - $used);
                $rows[] = ['date' => $date, 'product_id' => 0, 'service' => $name, 'name' => $name, 'requested' => $q, 'available' => $free, 'ok' => $q <= $free];
                if ($q > $free) {
                    $badSvc[$date.'|'.$key] = true;
                }
            }
        }

        $bad = array_values(array_filter($rows, fn ($r) => ! $r['ok']));
        $badKeys = [];
        foreach ($bad as $b) {
            $badKeys[$b['date'].'|'.$b['product_id']] = true;
        }
        $byLine = [];
        foreach ($lines as $l) {
            $lid = (string) ($l['id'] ?? '');
            $byLine[$lid] = [];
            $saleBad = (bool) array_intersect_key($this->saleUnitsForLine($l), $badSale);
            $svcKeys = ($l['type'] ?? '') === 'package' && ($ld = $this->lineDef($l))
                ? array_map(fn ($k) => $this->package($l['ref_id'])?->supplier_id.'|'.$k, array_keys(Packages::limitedServices($ld))) : [];
            foreach (array_keys($l['days'] ?? []) as $date) {
                $conflict = $saleBad;
                foreach ($svcKeys as $sk) {
                    $conflict = $conflict || isset($badSvc[$date.'|'.$sk]);
                }
                foreach (array_keys($this->unitsForLine($l, $date)) as $pid) {
                    $conflict = $conflict || isset($badKeys[$date.'|'.$pid]);
                }
                $win = $this->lineWindow($l, $date);
                $avail = ($l['type'] ?? 'item') === 'package'
                    ? $this->packageAvailableOn((int) $l['ref_id'], $date, $excludeId, $l['sel'] ?? null, ! empty($l['snap']) ? Packages::def($l['snap']) : null, $win)
                    : $this->availableOn((int) $l['ref_id'], $date, $excludeId, $win);
                $byLine[$lid][$date] = ['ok' => ! $conflict, 'available' => $avail, 'requested' => (int) $l['days'][$date]];
            }
        }

        return ['ok' => ! $bad, 'rows' => $rows, 'bad' => $bad, 'byLine' => $byLine];
    }

    /* ---------------- protection of accepted reservations ---------------- */

    /** Future dates (today or later) with accepted reservations of a product (optionally from one location). */
    public function reservedDates(int $pid, ?int $locId = null): array
    {
        $t = today_iso();
        $set = [];
        foreach ($this->openDemands() as $d) {
            if (! in_array($d['status'], self::RESERVING, true)) {
                continue;
            }
            foreach ($d['lines'] as $l) {
                if ($locId && (int) ($l['sourceId'] ?? 0) !== $locId) {
                    continue;
                }
                foreach (array_keys($l['days'] ?? []) as $date) {
                    if ($date >= $t && ! empty($this->unitsForLine($l, $date)[$pid])) {
                        $set[$date] = true;
                    }
                }
            }
        }
        $dates = array_keys($set);
        sort($dates);

        return $dates;
    }

    /** Dates where a new total / damaged / missing count would break accepted reservations. */
    public function stockChangeConflicts(int $pid, int $newTotal, ?int $newDamaged = null, ?int $newMissing = null): array
    {
        $p = $this->product($pid);
        if (! $p) {
            return [];
        }
        $out = [];
        foreach ($this->reservedDates($pid) as $d) {
            $h = $this->holdsOn($pid, $d);
            $reserved = $this->reservedOn($pid, $d);
            $capacity = $newTotal - ($newDamaged ?? $p->damaged) - ($newMissing ?? $p->missing) - (int) $p->written_off - $h['maintenance'] - $h['setaside'];
            if ($capacity < $reserved) {
                $out[] = ['date' => $d, 'reserved' => $reserved, 'capacity' => $capacity];
            }
        }

        return $out;
    }

    /** Dates where a location's new quantity would break reservations allocated to it. */
    public function locationStockConflicts(int $pid, int $locId, int $newQty): array
    {
        $out = [];
        foreach ($this->reservedDates($pid, $locId) as $d) {
            $reserved = $this->demandOn($pid, $d, self::RESERVING, null, null, $locId)['total'];
            if ($reserved > $newQty) {
                $out[] = ['date' => $d, 'reserved' => $reserved, 'capacity' => $newQty];
            }
        }

        return $out;
    }

    /** Dates where a new maintenance / set-aside hold would break accepted reservations. */
    public function holdConflicts(int $pid, int $qty, string $start, ?string $end): array
    {
        $p = $this->product($pid);
        if (! $p) {
            return [];
        }
        $last = $end ?: add_days($start, 60);
        $out = [];
        foreach ($this->reservedDates($pid) as $d) {
            if ($d < $start || $d > $last) {
                continue;
            }
            $reserved = $this->reservedOn($pid, $d);
            $capacity = $p->total_stock - $this->unusableOn($p, $d) - $qty;
            if ($capacity < $reserved) {
                $out[] = ['date' => $d, 'reserved' => $reserved, 'capacity' => $capacity];
            }
        }

        return $out;
    }
}
