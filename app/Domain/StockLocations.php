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
 * Branches / inventory locations (reference services.js saveLocation / deleteLocation / transferStock).
 * Each branch keeps its own dedicated stock; the product total is the sum and is never duplicated. A branch also
 * has its own address, city and map pin (delivery origin) and can be deactivated (no new rentals).
 */
class StockLocations
{
    public const MOVE_LABEL = ['initial' => 'Initial stock', 'adjust' => 'Stock adjustment', 'transfer' => 'Transfer', 'damaged' => 'Damaged (repairable)', 'repaired' => 'Repaired',
        'write_off' => 'Written off (non-repairable)', 'lost' => 'Lost / not returned', 'sold' => 'Sold', 'restock' => 'Restock'];

    private static function fail(string $e): array
    {
        return ['ok' => false, 'error' => $e];
    }

    /**
     * Add or edit a branch. $data: name, address (text) or addr (structured: region … street), lat, lng, contact,
     * phone, is_main. Coordinates must be inside the map area.
     */
    public static function save(Supplier $s, array $data, ?InventoryLocation $l = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $parts = is_array($data['addr'] ?? null) && ! empty($data['addr']['cityCode']) ? \App\Support\AddressInput::attributes($data['addr']) : null;
        $address = $parts ? $parts['address'] : trim((string) ($data['address'] ?? ''));
        if (mb_strlen($name) < 2) {
            return self::fail('Enter a branch name (e.g. Main Warehouse, Pasig Branch).');
        }
        if (mb_strlen($address) < 8) {
            return self::fail('Enter the complete address of the branch.');
        }
        if ($s->locations()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($l, fn ($q) => $q->where('id', '!=', $l->id))->exists()) {
            return self::fail('You already have a branch with that name.');
        }
        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '' && ! preg_match('/^(\+?63|0)\d{9,10}$/', preg_replace('/[\s\-()]/', '', $phone))) {
            return self::fail('Enter a valid contact number for the branch (e.g. 0917 555 0101 or (02) 8820 1234).');
        }
        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;
        $pin = is_numeric($lat) && is_numeric($lng) ? [round((float) $lat, 7), round((float) $lng, 7)] : null;
        if ($pin && ! Locations::inBounds($pin[0], $pin[1])) {
            return self::fail('That map pin is outside the area PartyRent serves. Move it to the branch.');
        }
        $rec = ['name' => $name, 'address' => $address, 'contact' => trim((string) ($data['contact'] ?? '')), 'phone' => $phone]
            + ($parts ? ['address_parts' => $parts['address_parts'], 'city_code' => $parts['city_code']] : [])
            + (array_key_exists('lat', $data) ? ['lat' => $pin[0] ?? null, 'lng' => $pin[1] ?? null] : []);
        DB::transaction(function () use ($s, &$l, $rec, $data) {
            $l ? $l->update($rec) : $l = $s->locations()->create($rec + ['active' => true, 'is_main' => ! $s->locations()->exists()]);
            if (! empty($data['is_main'])) {
                self::makeMain($s, $l);
            }
        });
        inv()->flush();
        Notifier::audit($l->wasRecentlyCreated ? 'Added branch' : 'Updated branch', $s->name.' · '.$name);

        return ['ok' => true, 'branch' => $l];
    }

    /** The supplier's main branch (shown first; new stock goes there). Never a deactivated one. */
    public static function makeMain(Supplier $s, InventoryLocation $l): void
    {
        $s->locations()->where('id', '!=', $l->id)->update(['is_main' => false]);
        $l->update(['is_main' => true, 'active' => true]);
    }

    /**
     * Deactivate (no new rentals; its stock stays recorded) or reactivate a branch. The main branch and branches
     * with open rentals cannot be deactivated: move those rentals to another branch first.
     */
    public static function setActive(Supplier $s, InventoryLocation $l, bool $on): array
    {
        if (! $on) {
            if ($l->is_main) {
                return self::fail($l->name.' is your main branch. Make another branch the main one before pausing it.');
            }
            if ($s->locations()->where('active', true)->where('id', '!=', $l->id)->doesntExist()) {
                return self::fail('At least one branch must stay open for rentals.');
            }
            $open = RentalRequest::where('branch_id', $l->id)->whereIn('status', array_merge(['pending'], Inventory::RESERVING))->pluck('code');
            if ($open->isNotEmpty()) {
                return self::fail($l->name.' still has open rentals ('.$open->take(5)->implode(', ').($open->count() > 5 ? ' and more' : '').'). Move those rentals to another branch first (open each rental and change its branch).');
            }
        }
        $l->update(['active' => $on]);
        inv()->flush();
        Notifier::audit($on ? 'Reactivated branch' : 'Deactivated branch', $s->name.' · '.$l->name);

        return ['ok' => true];
    }

    public static function delete(Supplier $s, InventoryLocation $l): array
    {
        if ($s->locations()->count() === 1) {
            return self::fail('Keep at least one branch.');
        }
        if ($l->is_main) {
            return self::fail('Make another branch your main branch before removing '.$l->name.'.');
        }
        $stocked = LocationStock::where('inventory_location_id', $l->id)->where('qty', '>', 0)->with('product')->get()->filter(fn ($x) => $x->product && $x->product->status !== 'deleted');
        if ($stocked->isNotEmpty()) {
            return self::fail($l->name.' still holds stock ('.$stocked->map(fn ($x) => $x->product->name.': '.$x->qty)->implode(', ').'). Transfer it to another location first.');
        }
        $booked = RentalRequest::where('supplier_id', $s->id)->whereIn('status', array_merge(['pending'], Inventory::RESERVING))->get()
            ->filter(fn ($r) => $r->branch_id === $l->id || collect($r->lines)->contains(fn ($x) => (int) ($x['sourceId'] ?? 0) === $l->id));
        if ($booked->isNotEmpty()) {
            return self::fail('Bookings are allocated to '.$l->name.' ('.$booked->pluck('code')->implode(', ').'). Change their allocation first.');
        }
        $l->delete();
        Notifier::audit('Removed branch', $s->name.' · '.$l->name);

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
