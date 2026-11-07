<?php

namespace App\Domain;

use App\Models\Hold;
use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;

/**
 * THE single source of truth for date-based availability (port of js/core/inventory.js).
 *
 *   Available(date) = Total − Reserved(date) − Damaged − Missing − Maintenance(date) − SetAside(date)
 *   Reserved(date)  = units in ACCEPTED-or-later rentals covering the date
 *                     (package lines expand into their component items)
 *
 * Pending requests never consume stock; they are reported as potential conflicts only.
 *
 * Registered as a singleton, so lookups are memoized for the current HTTP request.
 * Call flush() after any write that changes stock, holds or request statuses.
 */
class Inventory
{
    /** @var array<int, Product> */
    private array $products = [];

    /** @var array<int, Package|null> */
    private array $packages = [];

    /** @var array<int, array>|null product_id => list of holds */
    private ?array $holds = null;

    /** @var array<int, array{id:int,status:string,map:array}>|null */
    private ?array $demands = null;

    public function flush(): void
    {
        $this->products = [];
        $this->packages = [];
        $this->holds = null;
        $this->demands = null;
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
        }

        return $this->products[$id];
    }

    /** Preload many products in one query. */
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

    /** Display name of a line's item or package. */
    public function itemName(array $line): string
    {
        return $line['type'] === 'package'
            ? ($this->package($line['ref_id'])?->name ?? 'Package')
            : ($this->product($line['ref_id'])?->name ?? 'Item');
    }

    /* ---------------- demand ---------------- */

    /** Expand one line into [productId => qty] for one date. */
    public function unitsForLine(array $line, string $date): array
    {
        $q = (int) ($line['days'][$date] ?? 0);
        if (! $q) {
            return [];
        }
        if (($line['type'] ?? 'item') === 'package') {
            $k = $this->package($line['ref_id']);
            if (! $k) {
                return [];
            }
            $out = [];
            foreach ($k->items as $it) {
                $out[(int) $it['product_id']] = ($out[(int) $it['product_id']] ?? 0) + (int) $it['qty'] * $q;
            }

            return $out;
        }

        return [(int) $line['ref_id'] => $q];
    }

    /** Expand many lines into [date => [productId => qty]]. */
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

    /** Demand maps of every open request (reserving + pending), computed once per HTTP request. */
    private function openDemands(): array
    {
        if ($this->demands === null) {
            $this->demands = [];
            $rows = RentalRequest::whereIn('status', array_merge(RentalRequest::RESERVING, ['pending']))
                ->get(['id', 'status', 'lines']);
            foreach ($rows as $r) {
                $this->demands[] = ['id' => $r->id, 'code' => null, 'status' => $r->status, 'map' => $this->demandMap($r->lines ?? [])];
            }
        }

        return $this->demands;
    }

    /** @return array{total:int, refs:array} */
    public function demandOn(int $pid, string $date, array $statuses, ?int $excludeId = null): array
    {
        $total = 0;
        $refs = [];
        foreach ($this->openDemands() as $d) {
            if ($d['id'] === $excludeId || ! in_array($d['status'], $statuses, true)) {
                continue;
            }
            $q = $d['map'][$date][$pid] ?? 0;
            if ($q) {
                $total += $q;
                $refs[] = ['request_id' => $d['id'], 'qty' => $q, 'status' => $d['status']];
            }
        }

        return ['total' => $total, 'refs' => $refs];
    }

    public function reservedOn(int $pid, string $date, ?int $excludeId = null): int
    {
        return $this->demandOn($pid, $date, RentalRequest::RESERVING, $excludeId)['total'];
    }

    public function pendingOn(int $pid, string $date, ?int $excludeId = null): int
    {
        return $this->demandOn($pid, $date, ['pending'], $excludeId)['total'];
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
            if ($date < $h->start_date) {
                continue;
            }
            if ($h->end_date && $date > $h->end_date) {
                continue;
            }
            $out[$h->type === 'setaside' ? 'setaside' : 'maintenance'] += $h->qty;
            $out['list'][] = $h;
        }

        return $out;
    }

    /* ---------------- availability ---------------- */

    /** Full breakdown for one product on one date. */
    public function breakdown(int $pid, string $date, ?int $excludeId = null): ?array
    {
        $p = $this->product($pid);
        if (! $p) {
            return null;
        }
        $res = $this->demandOn($pid, $date, RentalRequest::RESERVING, $excludeId);
        $pen = $this->demandOn($pid, $date, ['pending'], $excludeId);
        $h = $this->holdsOn($pid, $date);
        $unusable = $p->damaged + $p->missing + $h['maintenance'] + $h['setaside'];

        return [
            'date' => $date, 'total' => $p->total_stock,
            'reserved' => $res['total'], 'reservedRefs' => $res['refs'],
            'pending' => $pen['total'], 'pendingRefs' => $pen['refs'],
            'damaged' => $p->damaged, 'missing' => $p->missing,
            'maintenance' => $h['maintenance'], 'setaside' => $h['setaside'], 'holds' => $h['list'],
            'available' => max(0, $p->total_stock - $res['total'] - $unusable),
        ];
    }

    public function availableOn(int $pid, string $date, ?int $excludeId = null): int
    {
        return $this->breakdown($pid, $date, $excludeId)['available'] ?? 0;
    }

    /** Whole packages rentable on a date (limited by the scarcest component). */
    public function packageAvailableOn(int $pkgId, string $date, ?int $excludeId = null): int
    {
        $k = $this->package($pkgId);
        if (! $k || ! $k->items) {
            return 0;
        }
        $min = PHP_INT_MAX;
        foreach ($k->items as $it) {
            $min = min($min, intdiv($this->availableOn((int) $it['product_id'], $date, $excludeId), max(1, (int) $it['qty'])));
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /** Min availability across dates (today when no dates). */
    public function minAvailable(int $pid, array $dates, ?int $excludeId = null): int
    {
        if (! $dates) {
            return $this->availableOn($pid, today_iso(), $excludeId);
        }

        return min(array_map(fn ($d) => $this->availableOn($pid, $d, $excludeId), $dates));
    }

    public function packageMinAvailable(int $pkgId, array $dates): int
    {
        if (! $dates) {
            return $this->packageAvailableOn($pkgId, today_iso());
        }

        return min(array_map(fn ($d) => $this->packageAvailableOn($pkgId, $d), $dates));
    }

    /**
     * Validate lines date by date. Lines for the same product (including via packages) are combined.
     *
     * @return array{ok:bool, rows:array, bad:array, byLine:array}
     */
    public function check(array $lines, ?int $excludeId = null): array
    {
        $map = $this->demandMap($lines);
        $rows = [];
        foreach ($map as $date => $pids) {
            foreach ($pids as $pid => $requested) {
                $available = $this->availableOn($pid, $date, $excludeId);
                $rows[] = ['date' => $date, 'product_id' => $pid, 'requested' => $requested, 'available' => $available, 'ok' => $requested <= $available];
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
            foreach (array_keys($l['days'] ?? []) as $date) {
                $conflict = false;
                foreach (array_keys($this->unitsForLine($l, $date)) as $pid) {
                    if (isset($badKeys[$date.'|'.$pid])) {
                        $conflict = true;
                    }
                }
                $avail = $l['type'] === 'package'
                    ? $this->packageAvailableOn((int) $l['ref_id'], $date, $excludeId)
                    : $this->availableOn((int) $l['ref_id'], $date, $excludeId);
                $byLine[$lid][$date] = ['ok' => ! $conflict, 'available' => $avail, 'requested' => (int) $l['days'][$date]];
            }
        }

        return ['ok' => ! $bad, 'rows' => $rows, 'bad' => $bad, 'byLine' => $byLine];
    }

    /** Future dates on which this product has accepted reservations. */
    public function reservedDates(int $pid): array
    {
        $t = today_iso();
        $set = [];
        foreach ($this->openDemands() as $d) {
            if (! in_array($d['status'], RentalRequest::RESERVING, true)) {
                continue;
            }
            foreach ($d['map'] as $date => $pids) {
                if ($date >= $t && ! empty($pids[$pid])) {
                    $set[$date] = true;
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
        $dmg = $newDamaged ?? $p->damaged;
        $mis = $newMissing ?? $p->missing;
        $out = [];
        foreach ($this->reservedDates($pid) as $d) {
            $h = $this->holdsOn($pid, $d);
            $reserved = $this->reservedOn($pid, $d);
            $capacity = $newTotal - $dmg - $mis - $h['maintenance'] - $h['setaside'];
            if ($capacity < $reserved) {
                $out[] = ['date' => $d, 'reserved' => $reserved, 'capacity' => $capacity];
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
            $h = $this->holdsOn($pid, $d);
            $reserved = $this->reservedOn($pid, $d);
            $capacity = $p->total_stock - $p->damaged - $p->missing - $h['maintenance'] - $h['setaside'] - $qty;
            if ($capacity < $reserved) {
                $out[] = ['date' => $d, 'reserved' => $reserved, 'capacity' => $capacity];
            }
        }

        return $out;
    }
}
