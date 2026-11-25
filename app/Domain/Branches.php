<?php

namespace App\Domain;

use App\Models\InventoryLocation;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Support\Near;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Multi-branch suppliers (one business, one public store, several physical branches).
 *
 * - A branch is an InventoryLocation. Each keeps its own stock of every rental item (location_stock).
 * - A rental has ONE home branch (rental_requests.branch_id): every line uses that branch's stock (line sourceId),
 *   so Inventory::check() validates the branch's stock for the dates and times, packages item by item. The home
 *   branch is the delivery origin (Pricing::deliveryFor) and the pickup place.
 * - Service area: a branch serves customers up to its service distance (inventory_locations.service_km, else the
 *   shop's default, Supplier::branchSettings()['serviceKm']) from where they are (App\Support\Near) or from the
 *   delivery address. A branch outside it is never offered, for delivery or pickup.
 * - At checkout the best branch is picked automatically: every item available there, serves the customer, then the
 *   lowest delivery fee, then the nearest. The customer may pick another eligible branch.
 * - Combining (off unless the shop turns it on, branchSettings()['combine']): when no single branch has everything,
 *   the home branch borrows the missing units from other branches that serve the customer and are within
 *   combineKm of it (line "from" = [productId => [branchId => qty]]). Borrowed units are booked at the lending
 *   branch; App\Domain\Transfers records moving them to the home branch and back. The customer still gets one
 *   rental, one price and one pickup / delivery point; the delivery fee counts from the home branch only.
 *
 * Items for sale (consumables) are not split by branch: they come from the supplier's stock on hand.
 */
class Branches
{
    /** The supplier's main branch; made from its registered address (and given the unplaced stock) when it has none. */
    public static function main(Supplier $s): InventoryLocation
    {
        $b = $s->branches()->first();
        if ($b && ! $b->is_main) {
            $b->update(['is_main' => true]);
        }
        if (! $b) {
            $b = $s->locations()->create([
                'name' => 'Main branch', 'address' => (string) ($s->address ?? ''), 'address_parts' => $s->address_parts, 'city_code' => $s->city_code,
                'lat' => $s->lat, 'lng' => $s->lng, 'contact' => (string) ($s->contact_person ?? ''), 'phone' => (string) ($s->phone ?? ''),
                'active' => true, 'is_main' => true,
            ]);
            self::placeStock($s, $b);
            $s->unsetRelation('locations');
            inv()->flush();
        }

        return $b;
    }

    /** Rental stock not yet placed at any branch goes to $b (a product's branch quantities always add up to its total). */
    public static function placeStock(Supplier $s, InventoryLocation $b): void
    {
        foreach (Product::where('supplier_id', $s->id)->where('listing_type', '!=', 'sale')->get(['id', 'total_stock']) as $p) {
            $placed = (int) LocationStock::where('product_id', $p->id)->sum('qty');
            if ($placed < (int) $p->total_stock) {
                $row = LocationStock::firstOrNew(['product_id' => $p->id, 'inventory_location_id' => $b->id]);
                $row->qty = (int) $row->qty + (int) $p->total_stock - $placed;
                $row->save();
            }
        }
    }

    /** Branches that take new rentals (main branch first). */
    public static function active(Supplier $s): Collection
    {
        self::main($s);

        return $s->branches()->where('active', true)->get();
    }

    /** Every line fulfilled by one branch. */
    public static function onBranch(array $lines, int $branchId): array
    {
        return array_map(function ($l) use ($branchId) {
            unset($l['from']);

            return array_merge($l, ['sourceId' => $branchId]);
        }, $lines);
    }

    /** How far this branch serves customers (km). */
    public static function serviceKm(InventoryLocation $b): float
    {
        return (float) ($b->service_km ?? $b->supplier?->branchSettings()['serviceKm'] ?? Supplier::BRANCH_DEFAULTS['serviceKm']);
    }

    /** Road distance from a branch to a point, or null when either is unknown. */
    public static function km(InventoryLocation $b, ?array $point): ?float
    {
        $from = $b->point();

        return $from && $point ? Locations::roadKm($from, $point) : null;
    }

    /** Does the branch serve this point? Unknown distance counts as served (no limit we can check). */
    public static function serves(InventoryLocation $b, ?array $point): bool
    {
        $km = self::km($b, $point);

        return $km === null || $km <= self::serviceKm($b);
    }

    /**
     * Active branches that serve where the customer is (Near), nearest first, with their distance:
     * [['branch' => InventoryLocation, 'km' => ?float], ...]. Location unknown: every active branch.
     */
    public static function near(Supplier $s, ?array $point = null): array
    {
        $point ??= Near::point();
        $out = [];
        foreach (self::active($s) as $b) {
            if (self::serves($b, $point)) {
                $out[] = ['branch' => $b, 'km' => self::km($b, $point)];
            }
        }
        usort($out, fn ($a, $b) => [$a['km'] ?? PHP_FLOAT_MAX, ! $a['branch']->is_main, $a['branch']->id] <=> [$b['km'] ?? PHP_FLOAT_MAX, ! $b['branch']->is_main, $b['branch']->id]);

        return $out;
    }

    /**
     * How many of an item (or whole packages) the customer can rent on a date from the branches near them: the best
     * single branch, or the nearest one plus what branches within combineKm of it can lend when the shop combines
     * branches (never more than the shop has). Null when nothing limits it (one branch, location unknown).
     */
    public static function availableNear(Supplier $s, string $type, int $refId, string $date, ?array $sel = null, ?array $win = null, ?array $point = null): ?int
    {
        $point ??= Near::point();
        if (! $point && self::active($s)->count() <= 1) {
            return null;
        }
        if ($type !== 'package' && inv()->product($refId)?->isSale()) {
            return null; // items for sale come from the shop's stock on hand, not per branch
        }
        $near = self::near($s, $point);
        if (! $near) {
            return 0;
        }
        $one = fn (InventoryLocation $b) => $type === 'package'
            ? inv()->packageAvailableOn($refId, $date, null, $sel, null, $win, $b->id)
            : inv()->availableAtLocation($refId, $b->id, $date, null, $win);
        $per = array_map(fn ($x) => $one($x['branch']), $near);
        if (! $s->branchSettings()['combine'] || count($per) === 1) {
            return max($per);
        }
        $home = $near[0]['branch'];
        $sum = $per[0];
        foreach (array_slice($near, 1, null, true) as $i => $x) {
            $d = self::km($home, $x['branch']->point());
            if ($d === null || $d <= (float) $s->branchSettings()['combineKm']) {
                $sum += $per[$i];
            }
        }
        $total = $type === 'package' ? inv()->packageAvailableOn($refId, $date, null, $sel, null, $win) : inv()->availableOn($refId, $date, null, $win);

        return min($total, max($sum, max($per)));
    }

    /** Stock check of the lines at one branch: ['ok', 'check', 'short' => "Only 3 × Gold Chair on Dec 1", …]. */
    public static function check(array $lines, InventoryLocation $b, ?int $excludeId = null): array
    {
        $chk = inv()->check(self::onBranch($lines, $b->id), $excludeId);
        $short = collect($chk['bad'])->map(fn ($x) => ($x['available'] > 0 ? 'only '.$x['available'].' × ' : 'no ').$x['name'].(empty($x['sale']) ? ' on '.fmt_date($x['date']) : ''))->unique()->values();

        return ['ok' => $chk['ok'], 'check' => $chk, 'short' => $short->all()];
    }

    /**
     * Every active branch for these lines and destinations, best first:
     * [['id','name','address','city','phone','main','ok','short','deliverable','fee','km','note','recommended'], …].
     * Not ok (missing stock) or not deliverable branches are listed last and are never recommended.
     */
    public static function options(Supplier $s, array $lines, string $fulfillment, array $destinations = [], ?int $excludeId = null, ?array $point = null): array
    {
        $delivery = $fulfillment === 'delivery';
        $point ??= Near::point();
        // where the branch must reach: every pinned delivery address, else where the customer is renting near
        $targets = $delivery ? array_values(array_filter(array_map(fn ($d) => isset($d['lat'], $d['lng']) && is_numeric($d['lat']) ? [(float) $d['lat'], (float) $d['lng']] : null, $destinations))) : [];
        $targets = $targets ?: ($point ? [$point] : []);
        $out = [];
        foreach (self::active($s) as $b) {
            $c = self::check($lines, $b, $excludeId);
            $fees = $delivery ? array_map(fn ($d) => Pricing::deliveryFor($s, $b, $d), $destinations ?: [[]]) : [];
            $kms = array_values(array_filter(array_map(fn ($t) => self::km($b, $t), $targets), fn ($k) => $k !== null));
            $bad = array_values(array_filter($fees, fn ($f) => ! $f['ok']));
            $far = $kms && max($kms) > self::serviceKm($b);
            $out[] = [
                'id' => $b->id, 'name' => $b->name, 'address' => $b->address, 'city' => $b->cityName(), 'phone' => $b->phone, 'hours' => $b->hours, 'main' => (bool) $b->is_main,
                'ok' => $c['ok'], 'short' => $c['short'], 'serves' => ! $far, 'deliverable' => ! $bad && ! $far,
                'note' => $far ? 'Outside the area '.$b->name.' serves (about '.max($kms).' km away; it serves up to '.rtrim(rtrim(number_format(self::serviceKm($b), 1), '0'), '.').' km)' : ($bad[0]['note'] ?? null),
                'fee' => r2(array_sum(array_column($fees, 'fee'))), 'km' => $kms ? max($kms) : null, 'recommended' => false,
            ];
        }
        usort($out, fn ($a, $b) => [! ($a['ok'] && $a['deliverable']), $a['fee'], $a['km'] ?? PHP_FLOAT_MAX, ! $a['main'], $a['id']]
            <=> [! ($b['ok'] && $b['deliverable']), $b['fee'], $b['km'] ?? PHP_FLOAT_MAX, ! $b['main'], $b['id']]);
        if ($out && $out[0]['ok'] && $out[0]['deliverable']) {
            $out[0]['recommended'] = true;
        }

        return $out;
    }

    /**
     * The branch for a rental: the customer's choice ($wanted) when it can fulfil everything, else the recommended
     * one. Fails (with the options) when no single branch has every item for the dates.
     */
    public static function choose(Supplier $s, array $lines, string $fulfillment, array $destinations, ?int $wanted = null, ?int $excludeId = null): array
    {
        $opts = self::options($s, $lines, $fulfillment, $destinations, $excludeId);
        $ok = fn ($o) => $o['ok'] && $o['deliverable'];
        if ($wanted) {
            $o = collect($opts)->firstWhere('id', $wanted);
            if (! $o) {
                return ['ok' => false, 'error' => 'That branch of '.$s->name.' is not taking rentals. Choose another branch.', 'options' => $opts];
            }
            if (! $o['ok'] && $o['deliverable'] && ($c = self::combine($s, $lines, $opts, $excludeId, $o['id']))) {
                return ['ok' => true, 'branch' => $c['home'], 'lines' => $c['lines'], 'borrow' => $c['borrow'], 'options' => $opts];
            }
            if (! $ok($o)) {
                $why = ! $o['ok'] ? 'does not have everything for your dates ('.implode('; ', array_slice($o['short'], 0, 3)).')' : ($o['note'] ?: 'does not deliver to your address');
                $alt = collect($opts)->first($ok);

                return ['ok' => false, 'error' => $o['name'].' '.$why.'.'.($alt ? ' '.$alt['name'].' can fulfil the whole rental.' : ''), 'options' => $opts];
            }

            return ['ok' => true, 'branch' => InventoryLocation::find($o['id']), 'options' => $opts];
        }
        $best = collect($opts)->first($ok);
        if (! $best && ($c = self::combine($s, $lines, $opts, $excludeId))) {
            return ['ok' => true, 'branch' => $c['home'], 'lines' => $c['lines'], 'borrow' => $c['borrow'], 'options' => $opts];
        }
        if (! $best) {
            $stock = collect($opts)->firstWhere('ok', true);

            return ['ok' => false, 'options' => $opts, 'error' => $stock
                ? ($stock['note'] ?: 'None of '.$s->name.'\'s branches delivers to your address.').' Choose pickup or another address.'
                : (count($opts) > 1
                    ? (collect($opts)->contains(fn ($o) => ! $o['serves']) ? 'The branches of '.$s->name.' near you do not have every item for your dates.' : 'No single branch of '.$s->name.' has every item for your dates.').self::maxHint($opts).' Lower the quantities or pick other dates.'
                    : $s->name.' does not have every item for your dates: '.implode('; ', array_slice($opts[0]['short'] ?? [], 0, 3)).'.')];
        }

        return ['ok' => true, 'branch' => InventoryLocation::find($best['id']), 'options' => $opts];
    }

    /**
     * Combine nearby branches (shop setting): a home branch that serves the customer, plus branches within combineKm of
     * it lending the units it lacks. Tries the nearest serving branch first. Returns ['home', 'lines', 'borrow'] or null.
     */
    public static function combine(Supplier $s, array $lines, array $opts, ?int $excludeId = null, ?int $homeId = null): ?array
    {
        $set = $s->branchSettings();
        if (! $set['combine']) {
            return null;
        }
        $serving = collect($opts)->filter(fn ($o) => $o['deliverable'])->sortBy(fn ($o) => [$o['km'] ?? PHP_FLOAT_MAX, ! $o['main'], $o['id']])->values();
        $homes = $homeId ? $serving->where('id', $homeId) : $serving;
        $branches = InventoryLocation::whereIn('id', $serving->pluck('id'))->get()->keyBy('id');
        // every serving branch may be the home; the plan that moves the fewest units wins, then the nearest home
        $best = null;
        foreach ($homes as $h) {
            $home = $branches[$h['id']];
            $donors = $serving->where('id', '!=', $home->id)->map(fn ($o) => ['b' => $branches[$o['id']], 'km' => self::km($home, $branches[$o['id']]->point())])
                ->filter(fn ($x) => $x['km'] === null || $x['km'] <= (float) $set['combineKm'])->sortBy(fn ($x) => $x['km'] ?? PHP_FLOAT_MAX)->pluck('b')->values()->all();
            if ($donors && ($plan = self::planBorrow($lines, $home, $donors, $excludeId))) {
                $moved = array_sum(array_map('array_sum', $plan['borrow']));
                if (! $best || $moved < $best['moved']) {
                    $best = $plan + ['home' => $home, 'moved' => $moved];
                }
            }
        }

        return $best;
    }

    /** Lines rented from $home with the missing units borrowed from $donors (in order), checked against real stock. */
    private static function planBorrow(array $lines, InventoryLocation $home, array $donors, ?int $excludeId): ?array
    {
        $base = self::onBranch($lines, $home->id);
        $need = []; // pid => date => units (all lines together)
        foreach ($base as $l) {
            foreach (array_keys(array_filter($l['days'] ?? [])) as $d) {
                foreach (inv()->unitsForLine($l, $d) as $pid => $q) {
                    $need[$pid][$d] = ($need[$pid][$d] ?? 0) + $q;
                }
            }
        }
        $borrow = [];
        foreach ($need as $pid => $byDate) {
            $p = inv()->product($pid);
            if (! $p || $p->isSale()) {
                continue;
            }
            $short = 0;
            foreach ($byDate as $d => $q) {
                $short = max($short, $q - inv()->availableAtLocation($pid, $home->id, $d, $excludeId));
            }
            foreach ($donors as $dn) {
                if ($short <= 0) {
                    break;
                }
                $free = min(array_map(fn ($d) => inv()->availableAtLocation($pid, $dn->id, $d, $excludeId), array_keys($byDate)));
                if (($take = min($short, $free)) > 0) {
                    $borrow[$pid][$dn->id] = $take;
                    $short -= $take;
                }
            }
            if ($short > 0) {
                return null;
            }
        }
        if (! $borrow) {
            return null;
        }
        // spread each loan over the lines that use the item: a line takes what it can, lines on the same date share
        $used = []; // pid|loc|date => units already taken
        foreach ($base as &$l) {
            $dates = array_keys(array_filter($l['days'] ?? []));
            foreach ($borrow as $pid => $byLoc) {
                $mine = array_map(fn ($d) => inv()->unitsForLine($l, $d)[$pid] ?? 0, $dates);
                if (! $mine || ! min($mine)) {
                    continue;
                }
                $room = min($mine);
                foreach ($byLoc as $loc => $amt) {
                    $take = min($room, ...array_map(fn ($d) => $amt - ($used[$pid.'|'.$loc.'|'.$d] ?? 0), $dates));
                    if ($take > 0) {
                        $l['from'][$pid][$loc] = $take;
                        $room -= $take;
                        foreach ($dates as $d) {
                            $used[$pid.'|'.$loc.'|'.$d] = ($used[$pid.'|'.$loc.'|'.$d] ?? 0) + $take;
                        }
                    }
                }
            }
        }
        unset($l);
        inv()->flush();

        return inv()->check($base, $excludeId)['ok'] ? ['lines' => $base, 'borrow' => $borrow] : null;
    }

    /** " Pasig branch has only 60 x Gold Chair on Dec 1." for the stock message: what the nearest serving branch lacks. */
    private static function maxHint(array $opts): string
    {
        $best = collect($opts)->filter(fn ($o) => $o['deliverable'])->first();

        return $best && $best['short'] ? ' '.$best['name'].' has '.implode('; ', array_slice($best['short'], 0, 2)).'.' : '';
    }

    /** Does this rental borrow units from other branches? */
    public static function isCombined(RentalRequest $r): bool
    {
        return collect($r->lines ?? [])->contains(fn ($l) => ! empty($l['from']));
    }

    /** Units of each item this rental borrows, by lending branch: [pid => [branchId => peak units]]. */
    public static function borrowed(RentalRequest $r): array
    {
        $peak = [];
        foreach ($r->lines ?? [] as $l) {
            foreach (array_keys(array_filter($l['days'] ?? [])) as $d) {
                foreach (inv()->unitsForLine($l, $d) as $pid => $q) {
                    foreach (inv()->lineSources($l, $pid, $q) as [$loc, $n]) {
                        if ($loc && $loc !== (int) $r->branch_id) {
                            $peak[$pid][$loc][$d] = ($peak[$pid][$loc][$d] ?? 0) + $n;
                        }
                    }
                }
            }
        }

        return array_map(fn ($byLoc) => array_map(fn ($byDate) => max($byDate), $byLoc), $peak);
    }

    /**
     * Supplier moves a rental to another of its branches (before the items are released). The new branch must have
     * every item for the dates; the quoted price is kept.
     */
    public static function reassign(RentalRequest $r, int $branchId): array
    {
        if (! in_array($r->status, ['pending', 'accepted', 'in_progress'], true)) {
            return ['ok' => false, 'error' => 'The branch can only be changed before the items are released.'];
        }
        $b = $r->supplier->locations()->where('active', true)->find($branchId);
        if (! $b) {
            return ['ok' => false, 'error' => 'Choose one of your active branches.'];
        }
        if ($r->branch_id === $b->id && ! self::isCombined($r)) {
            return ['ok' => true];
        }

        return DB::transaction(function () use ($r, $b) {
            Rentals::lockProducts($r->lines);
            inv()->flush();
            $c = self::check($r->lines, $b, $r->id);
            $lines = self::onBranch($r->lines, $b->id);
            if (! $c['ok']) {
                // a shop that combines branches: the new branch may borrow what it lacks from branches near it
                $opts = self::options($r->supplier, $r->lines, 'pickup', [], $r->id, $b->point());
                $plan = self::combine($r->supplier, $r->lines, $opts, $r->id, $b->id);
                if (! $plan) {
                    return ['ok' => false, 'error' => $b->name.' cannot fulfil '.$r->code.': '.implode('; ', array_slice($c['short'], 0, 3)).'.'];
                }
                $lines = $plan['lines'];
            }
            $from = $r->branch?->name ?? '—';
            $r->update(['branch_id' => $b->id, 'lines' => $lines]);
            BookingDays::sync($r);
            inv()->flush();
            Rentals::history($r, $r->status, 'Fulfilling branch changed: '.$from.' → '.$b->name);
            Notifier::audit('Changed fulfilling branch', $r->code.' · '.$from.' → '.$b->name);

            return ['ok' => true, 'from' => $from];
        });
    }

    /**
     * Rentals without a branch (made before branches, or by the demo seeder): the branch their lines already use, else
     * the supplier's main branch; lines without one get it. Returns how many were assigned.
     */
    public static function backfillRequests(): int
    {
        $n = 0;
        foreach (RentalRequest::whereNull('branch_id')->with('supplier')->get() as $r) {
            if (! $r->supplier) {
                continue;
            }
            $used = array_count_values(array_filter(array_map(fn ($l) => (int) ($l['sourceId'] ?? 0), $r->lines ?? [])));
            arsort($used);
            $branch = (int) (array_key_first($used) ?: self::main($r->supplier)->id);
            $r->forceFill(['branch_id' => $branch, 'lines' => array_map(fn ($l) => empty($l['sourceId']) ? array_merge($l, ['sourceId' => $branch]) : $l, $r->lines ?? [])])->saveQuietly();
            BookingDays::sync($r);
            $n++;
        }
        inv()->flush();

        return $n;
    }

    /** Extra branches named in a supplier application, created once it is approved (the main branch is its address). */
    public static function fromApplication(Supplier $s): void
    {
        self::main($s);
        foreach ((array) data_get($s->application, 'branches', []) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || $s->locations()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                continue;
            }
            $s->locations()->create(['name' => $name, 'address' => trim((string) ($row['address'] ?? '')), 'address_parts' => $row['addr'] ?? null, 'phone' => trim((string) ($row['phone'] ?? '')),
                'contact' => trim((string) ($row['contact'] ?? '')), 'city_code' => $row['cityCode'] ?? null, 'lat' => $row['lat'] ?? null, 'lng' => $row['lng'] ?? null,
                'active' => true, 'is_main' => false]);
        }
    }
}
