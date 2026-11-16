<?php

namespace App\Domain;

use App\Models\InventoryLocation;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Inventory locations (reference services.js saveLocation / deleteLocation / transferStock).
 * Each location keeps its own dedicated stock; the product total is the sum and is never duplicated.
 */
class StockLocations
{
    public const MOVE_LABEL = ['initial' => 'Initial stock', 'adjust' => 'Stock adjustment', 'transfer' => 'Transfer', 'damaged' => 'Damaged (repairable)', 'repaired' => 'Repaired',
        'write_off' => 'Written off (non-repairable)', 'lost' => 'Lost / not returned', 'sold' => 'Sold', 'restock' => 'Restock'];

    private static function fail(string $e): array
    {
        return ['ok' => false, 'error' => $e];
    }

    public static function save(Supplier $s, array $data, ?InventoryLocation $l = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        if (mb_strlen($name) < 2) {
            return self::fail('Enter a location name (e.g. Main Warehouse).');
        }
        if (mb_strlen($address) < 8) {
            return self::fail('Enter the complete address of the location.');
        }
        if ($s->locations()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($l, fn ($q) => $q->where('id', '!=', $l->id))->exists()) {
            return self::fail('You already have a location with that name.');
        }
        $rec = ['name' => $name, 'address' => $address, 'contact' => trim((string) ($data['contact'] ?? '')), 'phone' => trim((string) ($data['phone'] ?? ''))];
        $l ? $l->update($rec) : $s->locations()->create($rec);
        Notifier::audit($l ? 'Updated inventory location' : 'Added inventory location', $s->name.' · '.$name);

        return ['ok' => true];
    }

    public static function delete(Supplier $s, InventoryLocation $l): array
    {
        if ($s->locations()->count() === 1) {
            return self::fail('Keep at least one inventory location.');
        }
        $stocked = LocationStock::where('inventory_location_id', $l->id)->where('qty', '>', 0)->with('product')->get()->filter(fn ($x) => $x->product && $x->product->status !== 'deleted');
        if ($stocked->isNotEmpty()) {
            return self::fail($l->name.' still holds stock ('.$stocked->map(fn ($x) => $x->product->name.': '.$x->qty)->implode(', ').'). Transfer it to another location first.');
        }
        $booked = RentalRequest::where('supplier_id', $s->id)->whereIn('status', array_merge(['pending'], Inventory::RESERVING))->get()
            ->filter(fn ($r) => collect($r->lines)->contains(fn ($x) => (int) ($x['sourceId'] ?? 0) === $l->id));
        if ($booked->isNotEmpty()) {
            return self::fail('Bookings are allocated to '.$l->name.' ('.$booked->pluck('code')->implode(', ').'). Change their allocation first.');
        }
        $l->delete();
        Notifier::audit('Removed inventory location', $s->name.' · '.$l->name);

        return ['ok' => true];
    }

    /** Move units between two locations of the same supplier. Never breaks bookings allocated to the source. */
    public static function transfer(Product $p, int $fromId, int $toId, int $qty, string $note = ''): array
    {
        $locs = $p->supplier->locations()->get()->keyBy('id');
        if (! $locs->has($fromId) || ! $locs->has($toId)) {
            return self::fail('Choose two of your inventory locations.');
        }
        if ($fromId === $toId) {
            return self::fail('Choose a different destination location.');
        }
        if ($qty < 1) {
            return self::fail('Enter a quantity of at least 1.');
        }
        $have = inv()->stockAt($p->id, $fromId);
        if ($qty > $have) {
            return self::fail($locs[$fromId]->name.' only has '.$have.' unit(s) of '.$p->name.'.');
        }
        $left = $have - $qty;
        $need = [];
        foreach (inv()->reservedDates($p->id, $fromId) as $d) {
            $res = (int) inv()->demandOn($p->id, $d, Inventory::RESERVING, null, null, $fromId)['total'];
            if ($res > $left) {
                $need[] = $res.' on '.fmt_date($d);
            }
        }
        if ($need) {
            return self::fail('Bookings allocated to '.$locs[$fromId]->name.' still need '.implode(', ', array_slice($need, 0, 3)).'. Transfer fewer units or change those allocations.');
        }
        DB::transaction(function () use ($p, $fromId, $toId, $qty, $left, $note) {
            LocationStock::updateOrCreate(['product_id' => $p->id, 'inventory_location_id' => $fromId], ['qty' => $left]);
            $to = LocationStock::firstOrNew(['product_id' => $p->id, 'inventory_location_id' => $toId]);
            $to->qty = (int) $to->qty + $qty;
            $to->save();
            \App\Models\StockTransfer::create(['supplier_id' => $p->supplier_id, 'product_id' => $p->id, 'from_location_id' => $fromId, 'to_location_id' => $toId, 'qty' => $qty, 'note' => $note, 'user_id' => auth()->id(), 'created_at' => now()]);
            StockMovement::create(['product_id' => $p->id, 'qty' => $qty, 'type' => 'transfer', 'inventory_location_id' => $fromId, 'to_location_id' => $toId, 'user_id' => auth()->id(), 'balance' => (int) $p->total_stock, 'note' => $note, 'created_at' => now()]);
        });
        inv()->flush();
        Notifier::audit('Transferred stock', $p->name.' · '.$qty.' unit(s) '.$locs[$fromId]->name.' → '.$locs[$toId]->name);

        return ['ok' => true];
    }
}
