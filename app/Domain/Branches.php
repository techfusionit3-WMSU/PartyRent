<?php

namespace App\Domain;

use App\Models\InventoryLocation;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Multi-branch suppliers (one business, one public store, several physical branches).
 *
 * - A branch is an InventoryLocation. Each keeps its own stock of every rental item (location_stock).
 * - A rental is fulfilled by ONE branch (rental_requests.branch_id): every line uses that branch's stock
 *   (line sourceId), so Inventory::check() validates the branch's stock for the dates and times, packages
 *   item by item. Stock of another branch never makes up for a shortage.
 * - The branch is also the delivery origin (Pricing::deliveryFor) and the pickup place.
 * - At checkout the customer may pick an eligible branch; otherwise the best one is recommended: every item
 *   available there, deliverable, then the lowest delivery fee, then the shortest distance.
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
                'name' => 'Main branch', 'address' => (string) ($s->address ?? ''), 'city_code' => $s->city_code,
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
        return array_map(fn ($l) => array_merge($l, ['sourceId' => $branchId]), $lines);
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
    public static function options(Supplier $s, array $lines, string $fulfillment, array $destinations = [], ?int $excludeId = null): array
    {
        $delivery = $fulfillment === 'delivery';
        $out = [];
        foreach (self::active($s) as $b) {
            $c = self::check($lines, $b, $excludeId);
            $fees = $delivery ? array_map(fn ($d) => Pricing::deliveryFor($s, $b, $d), $destinations ?: [[]]) : [];
            $kms = array_values(array_filter(array_column($fees, 'km'), fn ($k) => $k !== null));
            $bad = array_values(array_filter($fees, fn ($f) => ! $f['ok']));
            $out[] = [
                'id' => $b->id, 'name' => $b->name, 'address' => $b->address, 'city' => $b->cityName(), 'phone' => $b->phone, 'main' => (bool) $b->is_main,
                'ok' => $c['ok'], 'short' => $c['short'], 'deliverable' => ! $bad, 'note' => $bad[0]['note'] ?? null,
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
            if (! $ok($o)) {
                $why = ! $o['ok'] ? 'does not have everything for your dates ('.implode('; ', array_slice($o['short'], 0, 3)).')' : ($o['note'] ?: 'does not deliver to your address');
                $alt = collect($opts)->first($ok);

                return ['ok' => false, 'error' => $o['name'].' '.$why.'.'.($alt ? ' '.$alt['name'].' can fulfil the whole rental.' : ''), 'options' => $opts];
            }

            return ['ok' => true, 'branch' => InventoryLocation::find($o['id']), 'options' => $opts];
        }
        $best = collect($opts)->first($ok);
        if (! $best) {
            $stock = collect($opts)->firstWhere('ok', true);

            return ['ok' => false, 'options' => $opts, 'error' => $stock
                ? ($stock['note'] ?: 'None of '.$s->name.'\'s branches delivers to your address.').' Choose pickup or another address.'
                : (count($opts) > 1
                    ? 'No single branch of '.$s->name.' has every item for your dates (each branch rents from its own stock). Lower the quantities, pick other dates, or rent the rest in a separate request.'
                    : $s->name.' does not have every item for your dates: '.implode('; ', array_slice($opts[0]['short'] ?? [], 0, 3)).'.')];
        }

        return ['ok' => true, 'branch' => InventoryLocation::find($best['id']), 'options' => $opts];
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
        if ($r->branch_id === $b->id) {
            return ['ok' => true];
        }

        return DB::transaction(function () use ($r, $b) {
            Rentals::lockProducts($r->lines);
            inv()->flush();
            $c = self::check($r->lines, $b, $r->id);
            if (! $c['ok']) {
                return ['ok' => false, 'error' => $b->name.' cannot fulfil '.$r->code.': '.implode('; ', array_slice($c['short'], 0, 3)).'.'];
            }
            $from = $r->branch?->name ?? '—';
            $r->update(['branch_id' => $b->id, 'lines' => self::onBranch($r->lines, $b->id)]);
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
            $s->locations()->create(['name' => $name, 'address' => trim((string) ($row['address'] ?? '')), 'phone' => trim((string) ($row['phone'] ?? '')),
                'contact' => '', 'city_code' => $row['cityCode'] ?? null, 'active' => true, 'is_main' => false]);
        }
    }
}
