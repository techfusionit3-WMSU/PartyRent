<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch suppliers. A supplier is the business (one public store); its branches are the physical places it
 * rents from. Branches reuse inventory_locations, which already hold each product's stock per place (location_stock).
 *
 *   inventory_locations  + city_code, lat, lng (delivery origin), active (deactivated branches take no new rentals),
 *                          is_main (the supplier's main branch)
 *   rental_requests      + branch_id: the branch that fulfils the whole rental (every rental line uses its stock)
 *
 * Existing data: every supplier gets a main branch (its first location, or one made from its own address and pin);
 * stock not yet placed at a location goes to the main branch; open rentals are assigned to the branch their lines
 * already use (or the main branch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $t) {
            $t->json('address_parts')->nullable()->after('address');
            $t->string('city_code', 12)->nullable()->after('address_parts');
            $t->decimal('lat', 10, 7)->nullable()->after('city_code');
            $t->decimal('lng', 10, 7)->nullable()->after('lat');
            $t->boolean('active')->default(true)->after('phone');
            $t->boolean('is_main')->default(false)->after('active');
            $t->index(['supplier_id', 'active']);
        });
        Schema::table('rental_requests', function (Blueprint $t) {
            $t->foreignId('branch_id')->nullable()->after('supplier_id')->constrained('inventory_locations')->nullOnDelete();
        });

        $now = now();
        foreach (DB::table('suppliers')->get(['id', 'name', 'address', 'city_code', 'lat', 'lng', 'phone', 'contact_person']) as $s) {
            $first = DB::table('inventory_locations')->where('supplier_id', $s->id)->orderBy('id')->first();
            if (! $first) {
                $mainId = DB::table('inventory_locations')->insertGetId([
                    'supplier_id' => $s->id, 'name' => 'Main branch', 'address' => (string) ($s->address ?? ''), 'city_code' => $s->city_code,
                    'lat' => $s->lat, 'lng' => $s->lng, 'contact' => (string) ($s->contact_person ?? ''), 'phone' => (string) ($s->phone ?? ''),
                    'active' => true, 'is_main' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $mainId = $first->id;
                // the main branch is the supplier's registered place: it takes the supplier's city and pin when it has none
                DB::table('inventory_locations')->where('id', $mainId)->update(['is_main' => true]
                    + ($first->city_code ?? null ? [] : ['city_code' => $s->city_code])
                    + ($first->lat ?? null ? [] : ['lat' => $s->lat, 'lng' => $s->lng]));
            }
            // rental stock that is not placed at any branch yet goes to the main branch (never counted twice)
            foreach (DB::table('products')->where('supplier_id', $s->id)->where('listing_type', '!=', 'sale')->get(['id', 'total_stock']) as $p) {
                $placed = (int) DB::table('location_stock')->where('product_id', $p->id)->sum('qty');
                if ($placed < (int) $p->total_stock) {
                    $row = DB::table('location_stock')->where('product_id', $p->id)->where('inventory_location_id', $mainId)->first();
                    $row
                        ? DB::table('location_stock')->where('id', $row->id)->update(['qty' => (int) $row->qty + (int) $p->total_stock - $placed])
                        : DB::table('location_stock')->insert(['product_id' => $p->id, 'inventory_location_id' => $mainId, 'qty' => (int) $p->total_stock - $placed]);
                }
            }
            // open rentals: the branch their lines already use, else the main branch; lines without one get it
            foreach (DB::table('rental_requests')->where('supplier_id', $s->id)->whereNull('branch_id')->whereNotIn('status', ['completed', 'declined', 'cancelled'])->get(['id', 'lines']) as $r) {
                $lines = json_decode((string) $r->lines, true) ?: [];
                $used = array_count_values(array_filter(array_map(fn ($l) => (int) ($l['sourceId'] ?? 0), $lines)));
                arsort($used);
                $branch = (int) (array_key_first($used) ?: $mainId);
                $lines = array_map(fn ($l) => empty($l['sourceId']) ? array_merge($l, ['sourceId' => $branch]) : $l, $lines);
                DB::table('rental_requests')->where('id', $r->id)->update(['branch_id' => $branch, 'lines' => json_encode($lines)]);
            }
        }
        if (DB::table('rental_requests')->exists()) {
            \App\Domain\BookingDays::syncAll(); // the per-day booking rows carry the branch of each line
        }
    }

    public function down(): void
    {
        Schema::table('rental_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
        Schema::table('inventory_locations', function (Blueprint $t) {
            $t->dropIndex(['supplier_id', 'active']);
            $t->dropColumn(['address_parts', 'city_code', 'lat', 'lng', 'active', 'is_main']);
        });
    }
};
